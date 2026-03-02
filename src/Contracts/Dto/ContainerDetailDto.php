<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Contracts\Dto;

final readonly class ContainerDetailDto
{
    /**
     * @param list<ContainerPortDto>    $ports
     * @param list<ContainerNetworkDto> $networks
     * @param list<string>              $envVars
     * @param array<string, string>     $labels
     * @param list<string>              $volumes
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $image,
        public string $status,
        public string $statusColor,
        public string $state,
        public array $ports,
        public array $networks,
        public string $created,
        public ?string $stackName,
        public bool $isSfPanel,
        public array $envVars,
        public array $labels,
        public array $volumes,
        public string $cmd,
        public string $entrypoint,
        public string $restartPolicy,
        public ?string $startedAt,
        public ?string $finishedAt,
    ) {
    }
}
