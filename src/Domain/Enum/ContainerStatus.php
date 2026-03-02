<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Domain\Enum;

enum ContainerStatus: string
{
    case Running = 'running';
    case Exited = 'exited';
    case Paused = 'paused';
    case Restarting = 'restarting';
    case Created = 'created';
    case Removing = 'removing';
    case Dead = 'dead';

    public function color(): string
    {
        return match ($this) {
            self::Running => 'green',
            self::Exited => 'gray',
            self::Paused => 'yellow',
            self::Restarting => 'blue',
            self::Created => 'purple',
            self::Removing => 'orange',
            self::Dead => 'red',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Exited => 'Exited',
            self::Paused => 'Paused',
            self::Restarting => 'Restarting',
            self::Created => 'Created',
            self::Removing => 'Removing',
            self::Dead => 'Dead',
        };
    }
}
