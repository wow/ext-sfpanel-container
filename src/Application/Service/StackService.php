<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Application\Service;

use Wow\Ext\Containers\Contracts\Dto\StackDto;
use Wow\Ext\Containers\Contracts\Event\StackDeployedEvent;
use Wow\Ext\Containers\Contracts\StackServiceInterface;
use Wow\Ext\Containers\Domain\Enum\StackStatus;
use Wow\Ext\Containers\Infrastructure\Adapter\DockerComposeAdapter;
use App\Shared\Attribute\AI\Describe;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Yaml\Yaml;

#[Describe('Manages Docker Compose stacks: create, deploy, down, delete, compose file editing', module: 'Container', layer: 'Application')]
final readonly class StackService implements StackServiceInterface
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';
    private const string PROJECT_PREFIX = 'sfpanel-stack-';

    public function __construct(
        private DockerComposeAdapter $composeAdapter,
        private MessageBusInterface $eventBus,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function list(): array
    {
        $containersDir = $this->getStacksBaseDir();

        if (!is_dir($containersDir)) {
            return [];
        }

        $entries = scandir($containersDir);

        if (false === $entries) {
            return [];
        }

        $stacks = [];

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || str_starts_with($entry, '.')) {
                continue;
            }

            $stackDir = $containersDir.'/'.$entry;

            if (!is_dir($stackDir) || !file_exists($stackDir.'/compose.yaml')) {
                continue;
            }

            if (!preg_match(self::NAME_PATTERN, $entry)) {
                continue;
            }

            $stacks[] = $this->buildStackDto($entry);
        }

        usort($stacks, fn (StackDto $a, StackDto $b): int => $a->name <=> $b->name);

        return $stacks;
    }

    public function get(string $name): StackDto
    {
        $this->assertStackExists($name);

        return $this->buildStackDto($name);
    }

    public function create(string $name, string $composeContent): StackDto
    {
        $this->validateName($name);
        $this->validateComposeContent($composeContent);

        $stackDir = $this->getStackDir($name);

        if (is_dir($stackDir)) {
            throw new \InvalidArgumentException(\sprintf('Stack "%s" already exists.', $name));
        }

        mkdir($stackDir, 0o755, true);
        file_put_contents($stackDir.'/compose.yaml', $composeContent);

        $this->logger->info('Stack created', ['name' => $name]);

        return $this->buildStackDto($name);
    }

    public function deploy(string $name): string
    {
        $this->assertStackExists($name);
        $stackDir = $this->getStackDir($name);
        $projectName = $this->getProjectName($name);

        $output = $this->composeAdapter->up($stackDir, $projectName);

        $this->eventBus->dispatch(new StackDeployedEvent(
            stackName: $name,
            projectName: $projectName,
        ));

        $this->logger->info('Stack deployed', ['name' => $name]);

        return $output;
    }

    public function down(string $name, bool $removeVolumes = false): string
    {
        $this->assertStackExists($name);
        $stackDir = $this->getStackDir($name);
        $projectName = $this->getProjectName($name);

        $output = $this->composeAdapter->down($stackDir, $projectName, $removeVolumes);

        $this->logger->info('Stack stopped', ['name' => $name, 'removeVolumes' => $removeVolumes]);

        return $output;
    }

    public function delete(string $name): string
    {
        $this->assertStackExists($name);
        $stackDir = $this->getStackDir($name);
        $projectName = $this->getProjectName($name);

        $output = '';

        try {
            $output = $this->composeAdapter->down($stackDir, $projectName);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Failed to stop stack before deletion', ['name' => $name, 'error' => $e->getMessage()]);
        }

        $this->removeDirectory($stackDir);

        $this->logger->info('Stack deleted', ['name' => $name]);

        return $output;
    }

    public function updateComposeFile(string $name, string $content): void
    {
        $this->assertStackExists($name);
        $this->validateComposeContent($content);

        $stackDir = $this->getStackDir($name);
        file_put_contents($stackDir.'/compose.yaml', $content);

        $this->logger->info('Compose file updated', ['name' => $name]);
    }

    public function getComposeFileContent(string $name): string
    {
        $this->assertStackExists($name);
        $filePath = $this->getStackDir($name).'/compose.yaml';

        return file_get_contents($filePath) ?: '';
    }

    private function buildStackDto(string $name): StackDto
    {
        $stackDir = $this->getStackDir($name);
        $projectName = $this->getProjectName($name);

        $containers = [];
        $runningCount = 0;
        $serviceCount = 0;
        $status = StackStatus::NotDeployed;

        try {
            $psData = $this->composeAdapter->ps($stackDir, $projectName);
            $serviceCount = \count($psData);

            foreach ($psData as $svc) {
                $state = $svc['State'] ?? 'unknown';
                if ('running' === $state) {
                    ++$runningCount;
                }
                $containers[] = $svc;
            }

            if ($serviceCount > 0 && $runningCount === $serviceCount) {
                $status = StackStatus::Running;
            } elseif ($runningCount > 0) {
                $status = StackStatus::Partial;
            } elseif ($serviceCount > 0) {
                $status = StackStatus::Stopped;
            }
        } catch (\RuntimeException) {
            // Stack might not be deployed yet
        }

        if (0 === $serviceCount) {
            try {
                $content = file_get_contents($stackDir.'/compose.yaml') ?: '';
                $parsed = Yaml::parse($content);
                $serviceCount = \count($parsed['services'] ?? []);
            } catch (\Throwable) {
                // Invalid YAML
            }
        }

        return new StackDto(
            name: $name,
            path: $stackDir,
            status: $status->value,
            statusColor: $status->color(),
            containers: $containers,
            hasComposeFile: true,
            isDeployed: StackStatus::NotDeployed !== $status && StackStatus::Stopped !== $status,
            serviceCount: $serviceCount,
            runningCount: $runningCount,
        );
    }

    private function assertStackExists(string $name): void
    {
        $stackDir = $this->getStackDir($name);

        if (!is_dir($stackDir) || !file_exists($stackDir.'/compose.yaml')) {
            throw new \InvalidArgumentException(\sprintf('Stack "%s" not found.', $name));
        }
    }

    private function getStacksBaseDir(): string
    {
        return $this->projectDir.'/mnt/ext/wow/ext-sfpanel-container/stacks';
    }

    private function getStackDir(string $name): string
    {
        return $this->getStacksBaseDir().'/'.$name;
    }

    private function getProjectName(string $name): string
    {
        return self::PROJECT_PREFIX.$name;
    }

    private function validateName(string $name): void
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('Stack name must not be empty.');
        }

        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('Stack name must contain only lowercase letters, numbers, and hyphens, start with a letter or number, and be at most 63 characters.');
        }
    }

    private function validateComposeContent(string $content): void
    {
        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Compose file content must not be empty.');
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid YAML syntax in compose file.');
        }

        if (!\is_array($parsed) || !isset($parsed['services'])) {
            throw new \InvalidArgumentException('Compose file must contain a "services" key.');
        }
    }

    private function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
