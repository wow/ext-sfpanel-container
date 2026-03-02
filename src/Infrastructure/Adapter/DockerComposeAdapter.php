<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Infrastructure\Adapter;

use App\Shared\Attribute\AI\Describe;
use App\Shared\Infrastructure\Command\CommandExecutorInterface;
use Psr\Log\LoggerInterface;

#[Describe('Wraps Docker Compose CLI commands for stack management', module: 'Container', layer: 'Infrastructure')]
final readonly class DockerComposeAdapter
{
    public function __construct(
        private CommandExecutorInterface $commandExecutor,
        private LoggerInterface $logger,
    ) {
    }

    public function up(string $stackDir, string $projectName): string
    {
        $this->validateComposeFileExists($stackDir);

        $result = $this->commandExecutor->execute(
            ['docker', 'compose', '-f', $stackDir.'/compose.yaml', '-p', $projectName, 'up', '-d'],
            timeout: 120,
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to deploy stack', [
                'stackDir' => $stackDir,
                'projectName' => $projectName,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to deploy stack: '.$result->errorOutput);
        }

        $this->logger->info('Stack deployed', ['stackDir' => $stackDir, 'projectName' => $projectName]);

        return $result->output.$result->errorOutput;
    }

    public function down(string $stackDir, string $projectName, bool $removeVolumes = false): string
    {
        $this->validateComposeFileExists($stackDir);

        $command = ['docker', 'compose', '-f', $stackDir.'/compose.yaml', '-p', $projectName, 'down'];
        if ($removeVolumes) {
            $command[] = '-v';
        }

        $result = $this->commandExecutor->execute($command, timeout: 120);

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to stop stack', [
                'stackDir' => $stackDir,
                'projectName' => $projectName,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to stop stack: '.$result->errorOutput);
        }

        $this->logger->info('Stack stopped', [
            'stackDir' => $stackDir,
            'projectName' => $projectName,
            'removeVolumes' => $removeVolumes,
        ]);

        return $result->output.$result->errorOutput;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ps(string $stackDir, string $projectName): array
    {
        $this->validateComposeFileExists($stackDir);

        $result = $this->commandExecutor->execute(
            ['docker', 'compose', '-f', $stackDir.'/compose.yaml', '-p', $projectName, 'ps', '--format', 'json'],
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to list stack containers', [
                'stackDir' => $stackDir,
                'projectName' => $projectName,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to list stack containers: '.$result->errorOutput);
        }

        $output = trim($result->output);
        if ('' === $output) {
            return [];
        }

        // Docker Compose ps --format json may output a JSON array or one JSON object per line
        $data = json_decode($output, true);

        if (\is_array($data) && ([] === $data || array_is_list($data))) {
            return $data;
        }

        // Fallback: one JSON per line
        $containers = [];
        foreach (array_filter(explode("\n", $output)) as $line) {
            $parsed = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($parsed)) {
                $containers[] = $parsed;
            }
        }

        return $containers;
    }

    public function pull(string $stackDir, string $projectName): void
    {
        $this->validateComposeFileExists($stackDir);

        $result = $this->commandExecutor->execute(
            ['docker', 'compose', '-f', $stackDir.'/compose.yaml', '-p', $projectName, 'pull'],
            timeout: 120,
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to pull stack images', [
                'stackDir' => $stackDir,
                'projectName' => $projectName,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to pull stack images: '.$result->errorOutput);
        }

        $this->logger->info('Stack images pulled', ['stackDir' => $stackDir, 'projectName' => $projectName]);
    }

    public function logs(string $stackDir, string $projectName, int $tail = 200): string
    {
        $this->validateComposeFileExists($stackDir);

        $result = $this->commandExecutor->execute(
            ['docker', 'compose', '-f', $stackDir.'/compose.yaml', '-p', $projectName, 'logs', '--tail', (string)$tail],
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('Failed to get stack logs', [
                'stackDir' => $stackDir,
                'projectName' => $projectName,
                'error' => $result->errorOutput,
            ]);

            throw new \RuntimeException('Failed to get stack logs: '.$result->errorOutput);
        }

        return $result->output.$result->errorOutput;
    }

    private function validateComposeFileExists(string $stackDir): void
    {
        $composeFile = $stackDir.'/compose.yaml';

        if (!file_exists($composeFile)) {
            throw new \RuntimeException('Compose file not found: '.$composeFile);
        }
    }
}
