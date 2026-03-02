<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Contracts\Dto;

final readonly class StackDto
{
    /**
     * @param list<array<string, mixed>> $containers
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $status,
        public string $statusColor,
        public array $containers,
        public bool $hasComposeFile,
        public bool $isDeployed,
        public int $serviceCount,
        public int $runningCount,
    ) {
    }
}
