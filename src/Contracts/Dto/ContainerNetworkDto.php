<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Contracts\Dto;

final readonly class ContainerNetworkDto
{
    public function __construct(
        public string $name,
        public string $ipAddress,
        public string $gateway,
        public string $macAddress,
    ) {
    }
}
