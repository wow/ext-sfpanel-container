<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Application\Service;

use App\Shared\Extension\Menu\MenuItem;
use App\Shared\Extension\Menu\MenuItemProviderInterface;

final readonly class ContainerMenuProvider implements MenuItemProviderInterface
{
    public function getMenuItems(): array
    {
        return [
            new MenuItem(
                label: 'nav.server.containers',
                route: 'panel_containers',
                icon: 'M21 7.5V18M15 7.5V18M3 16.811V8.69c0-.864.933-1.406 1.683-.977l7.108 4.061a1.125 1.125 0 010 1.954l-7.108 4.061A1.125 1.125 0 013 16.811z',
                section: 'server',
                priority: 35,
            ),
        ];
    }
}
