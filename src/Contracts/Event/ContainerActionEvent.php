<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Contracts\Event;

final readonly class ContainerActionEvent
{
    public function __construct(
        public string $containerId,
        public string $containerName,
        public string $action,
        public bool $isSfPanel,
    ) {
    }
}
