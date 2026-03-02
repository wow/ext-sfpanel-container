<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Contracts;

use SfPanel\Ext\Containers\Contracts\Dto\ContainerDetailDto;
use SfPanel\Ext\Containers\Contracts\Dto\ContainerDto;
use SfPanel\Ext\Containers\Contracts\Dto\ContainerStatsDto;

interface ContainerServiceInterface
{
    /**
     * @return ContainerDto[]
     */
    public function list(): array;

    public function get(string $id): ContainerDetailDto;

    public function start(string $id): void;

    public function stop(string $id): void;

    public function restart(string $id): void;

    public function remove(string $id): void;

    public function getLogs(string $id, int $tail = 200, bool $timestamps = false): string;

    public function getStats(string $id): ContainerStatsDto;
}
