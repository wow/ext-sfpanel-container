<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Application\Service;

use Wow\Ext\Containers\Contracts\ContainerServiceInterface;
use Wow\Ext\Containers\Contracts\Dto\ContainerDetailDto;
use Wow\Ext\Containers\Contracts\Dto\ContainerDto;
use Wow\Ext\Containers\Contracts\Dto\ContainerNetworkDto;
use Wow\Ext\Containers\Contracts\Dto\ContainerPortDto;
use Wow\Ext\Containers\Contracts\Dto\ContainerStatsDto;
use Wow\Ext\Containers\Contracts\Event\ContainerActionEvent;
use Wow\Ext\Containers\Domain\Enum\ContainerStatus;
use Wow\Ext\Containers\Infrastructure\Adapter\DockerCliAdapter;
use App\Shared\Attribute\AI\Describe;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[Describe('Manages Docker containers: listing, inspection, lifecycle, logs, stats', module: 'Container', layer: 'Application')]
final readonly class ContainerService implements ContainerServiceInterface
{
    public function __construct(
        private DockerCliAdapter $dockerCli,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function list(): array
    {
        $rawContainers = $this->dockerCli->listContainers();

        return array_map(fn (array $data): ContainerDto => $this->toContainerDto($data), $rawContainers);
    }

    public function get(string $id): ContainerDetailDto
    {
        $this->validateContainerId($id);
        $data = $this->dockerCli->inspectContainer($id);

        return $this->toContainerDetailDto($data);
    }

    public function start(string $id): void
    {
        $this->validateContainerId($id);
        $this->guardSfPanelContainer($id, 'start');

        $this->dockerCli->start($id);
        $this->dispatchAction($id, 'start');

        $this->logger->info('Container started', ['id' => $id]);
    }

    public function stop(string $id): void
    {
        $this->validateContainerId($id);
        $this->guardSfPanelContainer($id, 'stop');

        $this->dockerCli->stop($id);
        $this->dispatchAction($id, 'stop');

        $this->logger->info('Container stopped', ['id' => $id]);
    }

    public function restart(string $id): void
    {
        $this->validateContainerId($id);
        $this->guardSfPanelContainer($id, 'restart');

        $this->dockerCli->restart($id);
        $this->dispatchAction($id, 'restart');

        $this->logger->info('Container restarted', ['id' => $id]);
    }

    public function remove(string $id): void
    {
        $this->validateContainerId($id);
        $this->guardSfPanelContainer($id, 'remove');

        $this->dockerCli->remove($id);
        $this->dispatchAction($id, 'remove');

        $this->logger->info('Container removed', ['id' => $id]);
    }

    public function getLogs(string $id, int $tail = 200, bool $timestamps = false): string
    {
        $this->validateContainerId($id);

        return $this->dockerCli->getLogs($id, $tail, $timestamps);
    }

    public function getStats(string $id): ContainerStatsDto
    {
        $this->validateContainerId($id);
        $data = $this->dockerCli->getStats($id);

        return $this->toStatsDto($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toContainerDto(array $data): ContainerDto
    {
        $state = $data['State'] ?? 'unknown';
        $status = ContainerStatus::tryFrom($state) ?? ContainerStatus::Dead;
        $isSfPanel = $data['isSfPanel'] ?? false;

        $ports = $this->parsePorts($data['Ports'] ?? '');
        $networks = $this->parseNetworks($data['Networks'] ?? '');
        $stackName = $this->parseLabelValue($data['Labels'] ?? '', 'com.docker.compose.project');

        return new ContainerDto(
            id: $data['ID'] ?? '',
            name: ltrim($data['Names'] ?? $data['Name'] ?? '', '/'),
            image: $data['Image'] ?? '',
            status: $data['Status'] ?? $state,
            statusColor: $status->color(),
            state: $state,
            ports: $ports,
            networks: $networks,
            created: $data['CreatedAt'] ?? '',
            stackName: $stackName,
            isSfPanel: $isSfPanel,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toContainerDetailDto(array $data): ContainerDetailDto
    {
        $state = $data['State']['Status'] ?? 'unknown';
        $status = ContainerStatus::tryFrom($state) ?? ContainerStatus::Dead;
        $config = $data['Config'] ?? [];
        $hostConfig = $data['HostConfig'] ?? [];
        $networkSettings = $data['NetworkSettings'] ?? [];
        $labels = $config['Labels'] ?? [];
        $isSfPanel = ($labels['com.docker.compose.project'] ?? '') === 'sfpanel';

        $ports = [];
        foreach (($networkSettings['Ports'] ?? []) as $containerPort => $bindings) {
            if (!\is_array($bindings)) {
                continue;
            }
            [$port, $proto] = explode('/', $containerPort) + [0, 'tcp'];
            foreach ($bindings as $binding) {
                $ports[] = new ContainerPortDto(
                    hostIp: $binding['HostIp'] ?? '0.0.0.0',
                    hostPort: (int)($binding['HostPort'] ?? 0),
                    containerPort: (int)$port,
                    protocol: $proto,
                );
            }
        }

        $networks = [];
        foreach (($networkSettings['Networks'] ?? []) as $name => $net) {
            if (\is_array($net)) {
                $networks[] = new ContainerNetworkDto(
                    name: $name,
                    ipAddress: $net['IPAddress'] ?? '',
                    gateway: $net['Gateway'] ?? '',
                    macAddress: $net['MacAddress'] ?? '',
                );
            }
        }

        $volumes = [];
        foreach (($data['Mounts'] ?? []) as $mount) {
            if (\is_array($mount)) {
                $volumes[] = ($mount['Source'] ?? '').':'.($mount['Destination'] ?? '').($mount['RW'] ? '' : ':ro');
            }
        }

        return new ContainerDetailDto(
            id: $data['Id'] ?? '',
            name: ltrim($data['Name'] ?? '', '/'),
            image: $config['Image'] ?? '',
            status: $data['State']['Status'] ?? $state,
            statusColor: $status->color(),
            state: $state,
            ports: $ports,
            networks: $networks,
            created: $data['Created'] ?? '',
            stackName: $labels['com.docker.compose.project'] ?? null,
            isSfPanel: $isSfPanel,
            envVars: $config['Env'] ?? [],
            labels: $labels,
            volumes: $volumes,
            cmd: implode(' ', $config['Cmd'] ?? []),
            entrypoint: implode(' ', $config['Entrypoint'] ?? []),
            restartPolicy: $hostConfig['RestartPolicy']['Name'] ?? 'no',
            startedAt: $data['State']['StartedAt'] ?? null,
            finishedAt: $data['State']['FinishedAt'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toStatsDto(array $data): ContainerStatsDto
    {
        $memUsage = $data['MemUsage'] ?? '0 / 0';
        $netIO = $data['NetIO'] ?? '0B / 0B';
        $blockIO = $data['BlockIO'] ?? '0B / 0B';

        return new ContainerStatsDto(
            cpuPercent: (float)rtrim($data['CPUPerc'] ?? '0', '%'),
            memoryUsage: $this->parseSize($memUsage),
            memoryLimit: $this->parseSize($memUsage, true),
            memoryPercent: (float)rtrim($data['MemPerc'] ?? '0', '%'),
            networkRx: $this->parseSize($netIO),
            networkTx: $this->parseSize($netIO, true),
            blockRead: $this->parseSize($blockIO),
            blockWrite: $this->parseSize($blockIO, true),
            pids: (int)($data['PIDs'] ?? 0),
        );
    }

    private function parseSize(string $value, bool $isLimit = false): int
    {
        $value = trim($value);
        if (str_contains($value, '/')) {
            $parts = explode('/', $value);
            $value = trim($isLimit ? ($parts[1] ?? '0') : $parts[0]);
        }

        if (preg_match('/^([\d.]+)\s*(B|KiB|MiB|GiB|TiB|KB|MB|GB|TB|kB)?$/i', $value, $m)) {
            $num = (float)$m[1];
            $unit = strtoupper($m[2] ?? 'B');

            return (int)match ($unit) {
                'B' => $num,
                'KB', 'KIB' => $num * 1024,
                'MB', 'MIB' => $num * 1024 * 1024,
                'GB', 'GIB' => $num * 1024 * 1024 * 1024,
                'TB', 'TIB' => $num * 1024 * 1024 * 1024 * 1024,
                default => $num,
            };
        }

        return 0;
    }

    private function validateContainerId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{12,64}$/', $id)) {
            throw new \InvalidArgumentException(\sprintf('Invalid container ID: %s', $id));
        }
    }

    private function guardSfPanelContainer(string $id, string $action): void
    {
        try {
            $data = $this->dockerCli->inspectContainer($id);
            $labels = $data['Config']['Labels'] ?? [];
            $isSfPanel = ($labels['com.docker.compose.project'] ?? '') === 'sfpanel';

            if ($isSfPanel) {
                throw new \RuntimeException(\sprintf('Cannot %s sfPanel system container.', $action));
            }
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Cannot')) {
                throw $e;
            }
            $this->logger->warning('Could not verify sfPanel container status', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return list<ContainerPortDto>
     */
    private function parsePorts(mixed $ports): array
    {
        if (!\is_string($ports) || '' === trim($ports)) {
            return [];
        }

        $result = [];
        // Format: "0.0.0.0:5432->5432/tcp, :::8080->80/tcp" or "5432/tcp"
        foreach (explode(', ', $ports) as $mapping) {
            $mapping = trim($mapping);
            if ('' === $mapping) {
                continue;
            }

            $protocol = 'tcp';
            if (preg_match('#/(\w+)$#', $mapping, $m)) {
                $protocol = $m[1];
                $mapping = substr($mapping, 0, -\strlen($m[0]));
            }

            if (str_contains($mapping, '->')) {
                [$hostPart, $containerPort] = explode('->', $mapping, 2);
                $hostIp = '0.0.0.0';
                $hostPort = 0;

                if (str_contains($hostPart, ':')) {
                    $lastColon = strrpos($hostPart, ':');
                    $hostIp = substr($hostPart, 0, $lastColon) ?: '0.0.0.0';
                    $hostPort = (int)substr($hostPart, $lastColon + 1);
                    if ('::' === $hostIp) {
                        $hostIp = '::';
                    }
                }

                $result[] = new ContainerPortDto(
                    hostIp: $hostIp,
                    hostPort: $hostPort,
                    containerPort: (int)$containerPort,
                    protocol: $protocol,
                );
            } else {
                $result[] = new ContainerPortDto(
                    hostIp: '0.0.0.0',
                    hostPort: 0,
                    containerPort: (int)$mapping,
                    protocol: $protocol,
                );
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function parseNetworks(mixed $networks): array
    {
        if (!\is_string($networks) || '' === trim($networks)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $networks))));
    }

    private function parseLabelValue(mixed $labels, string $key): ?string
    {
        if (!\is_string($labels) || '' === $labels) {
            return null;
        }

        foreach (explode(',', $labels) as $label) {
            $parts = explode('=', $label, 2);
            if (2 === \count($parts) && trim($parts[0]) === $key) {
                return trim($parts[1]);
            }
        }

        return null;
    }

    private function dispatchAction(string $id, string $action): void
    {
        try {
            $data = $this->dockerCli->inspectContainer($id);
            $name = ltrim($data['Name'] ?? '', '/');
            $labels = $data['Config']['Labels'] ?? [];
            $isSfPanel = ($labels['com.docker.compose.project'] ?? '') === 'sfpanel';

            $this->eventBus->dispatch(new ContainerActionEvent(
                containerId: $id,
                containerName: $name,
                action: $action,
                isSfPanel: $isSfPanel,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to dispatch container action event', ['id' => $id, 'action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
