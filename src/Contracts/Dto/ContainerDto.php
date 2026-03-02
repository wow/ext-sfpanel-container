<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Contracts\Dto;

final readonly class ContainerDto
{
    /**
     * @param list<ContainerPortDto> $ports
     * @param list<string>           $networks
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
    ) {
    }
}
