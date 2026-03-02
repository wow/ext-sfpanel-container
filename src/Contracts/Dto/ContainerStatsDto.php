<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Contracts\Dto;

final readonly class ContainerStatsDto
{
    public function __construct(
        public float $cpuPercent,
        public int $memoryUsage,
        public int $memoryLimit,
        public float $memoryPercent,
        public int $networkRx,
        public int $networkTx,
        public int $blockRead,
        public int $blockWrite,
        public int $pids,
    ) {
    }
}
