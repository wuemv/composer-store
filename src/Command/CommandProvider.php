<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Composer constructs it with the composer, io and plugin instances, which the commands do not need.
 */
final class CommandProvider implements CommandProviderCapability
{
    /**
     * @return list<StoreCommand>
     */
    public function getCommands(): array
    {
        return [
            new StatusCommand(),
            new VerifyCommand(),
            new PruneCommand(),
            new MonitorCommand(),
            new DashboardCommand(),
        ];
    }
}
