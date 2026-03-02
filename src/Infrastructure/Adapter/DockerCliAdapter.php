<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Infrastructure\Adapter;

use App\Shared\Attribute\AI\Describe;
use App\Shared\Infrastructure\Command\CommandExecutorInterface;
use Psr\Log\LoggerInterface;

#[Describe('Wraps Docker CLI commands for container management', module: 'Container', layer: 'Infrastructure')]
final readonly class DockerCliAdapter
{
    private const string CONTAINER_ID_PATTERN = '/^[a-f0-9]{12,64}$/';

    public function __construct(
        private CommandExecutorInterface $commandExecutor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listContainers(bool $all = true): array
    {
        $command = ['docker', 'ps', '--format', '{{json .}}'];
        if ($all) {
            $command[] = '--all';
        }

        $result = $this->commandExecutor->execute($command);

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to list containers', [
                'exitCode' => $result->exitCode,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to list Docker containers: '.$result->errorOutput);
        }

        $containers = [];
        $lines = array_filter(explode("\n", trim($result->output)));

        foreach ($lines as $line) {
            $data = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                $this->logger->warning('Failed to parse container JSON line', ['line' => $line]);

                continue;
            }

            // Filter out sfpanel-restart-* helper containers
            $name = $data['Names'] ?? '';
            if (str_starts_with($name, 'sfpanel-restart-')) {
                continue;
            }

            // Detect sfPanel containers by label
            $labels = $data['Labels'] ?? '';
            $data['isSfPanel'] = str_contains($labels, 'com.docker.compose.project=sfpanel');

            $containers[] = $data;
        }

        return $containers;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectContainer(string $id): array
    {
        $this->validateContainerId($id);

        $result = $this->commandExecutor->execute(
            ['docker', 'inspect', '--format', '{{json .}}', $id],
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to inspect container', [
                'id' => $id,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to inspect container: '.$result->errorOutput);
        }

        $data = json_decode(trim($result->output), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \RuntimeException('Unexpected inspect output for container: '.$id);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(string $id): array
    {
        $this->validateContainerId($id);

        $result = $this->commandExecutor->execute(
            ['docker', 'stats', '--no-stream', '--format', '{{json .}}', $id],
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to get container stats', [
                'id' => $id,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to get container stats: '.$result->errorOutput);
        }

        $data = json_decode(trim($result->output), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \RuntimeException('Unexpected stats output for container: '.$id);
        }

        return $data;
    }

    public function getLogs(string $id, int $tail = 200, bool $timestamps = false): string
    {
        $this->validateContainerId($id);

        $command = ['docker', 'logs', '--tail', (string)$tail];
        if ($timestamps) {
            $command[] = '--timestamps';
        }
        $command[] = $id;

        $result = $this->commandExecutor->execute($command);

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to get container logs', [
                'id' => $id,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to get container logs: '.$result->errorOutput);
        }

        // Docker logs outputs to both stdout and stderr
        return $result->output.$result->errorOutput;
    }

    public function start(string $id): void
    {
        $this->validateContainerId($id);

        $result = $this->commandExecutor->execute(['docker', 'start', $id]);

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to start container', [
                'id' => $id,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to start container: '.$result->errorOutput);
        }

        $this->logger->info('Container started', ['id' => $id]);
    }

    public function stop(string $id): void
    {
        $this->validateContainerId($id);

        $result = $this->commandExecutor->execute(['docker', 'stop', $id]);

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to stop container', [
                'id' => $id,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to stop container: '.$result->errorOutput);
        }

        $this->logger->info('Container stopped', ['id' => $id]);
    }

    public function restart(string $id): void
    {
        $this->validateContainerId($id);

        $result = $this->commandExecutor->execute(['docker', 'restart', $id]);

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to restart container', [
                'id' => $id,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to restart container: '.$result->errorOutput);
        }

        $this->logger->info('Container restarted', ['id' => $id]);
    }

    public function remove(string $id, bool $force = false): void
    {
        $this->validateContainerId($id);

        $command = ['docker', 'rm'];
        if ($force) {
            $command[] = '-f';
        }
        $command[] = $id;

        $result = $this->commandExecutor->execute($command);

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to remove container', [
                'id' => $id,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to remove container: '.$result->errorOutput);
        }

        $this->logger->info('Container removed', ['id' => $id, 'force' => $force]);
    }

    private function validateContainerId(string $id): void
    {
        if (!preg_match(self::CONTAINER_ID_PATTERN, $id)) {
            throw new \InvalidArgumentException('Invalid container ID: '.$id);
        }
    }
}
