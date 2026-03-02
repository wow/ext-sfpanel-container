<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Contracts\Event;

final readonly class StackDeployedEvent
{
    public function __construct(
        public string $stackName,
        public string $projectName,
    ) {
    }
}
