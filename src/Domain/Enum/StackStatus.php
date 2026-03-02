<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Domain\Enum;

enum StackStatus: string
{
    case Running = 'running';
    case Partial = 'partial';
    case Stopped = 'stopped';
    case NotDeployed = 'not_deployed';

    public function color(): string
    {
        return match ($this) {
            self::Running => 'green',
            self::Partial => 'yellow',
            self::Stopped => 'gray',
            self::NotDeployed => 'blue',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Partial => 'Partial',
            self::Stopped => 'Stopped',
            self::NotDeployed => 'Not Deployed',
        };
    }
}
