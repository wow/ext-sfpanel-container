<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Contracts\Dto;

final readonly class ContainerPortDto
{
    public function __construct(
        public string $hostIp,
        public int $hostPort,
        public int $containerPort,
        public string $protocol,
    ) {
    }
}
