<?php

declare(strict_types=1);

namespace Wow\Ext\Containers;

use App\Shared\Extension\AbstractExtension;

class Extension extends AbstractExtension
{
    public function install(): void
    {
        // The mnt/ext/wow/ext-sfpanel-container/stacks/ directory is managed by StackService at runtime
    }

    public function activate(): void
    {
        // No-op — CompilerPass handles service registration
    }

    public function deactivate(): void
    {
        // No-op — running containers are unaffected
    }

    public function uninstall(bool $keepData): void
    {
        // Stack data lives in mnt/ext/wow/ext-sfpanel-container/stacks/ which is a bind-mounted host directory.
        // Cleaning it up would require the StackService to stop all stacks first.
        // For safety, we leave stack data intact on uninstall.
    }
}
