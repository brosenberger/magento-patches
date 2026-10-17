<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches;

use BroCode\MagentoPatches\Command\AddCommand;
use BroCode\MagentoPatches\Command\QualityCommand;
use BroCode\MagentoPatches\Command\RemoveCommand;
use BroCode\MagentoPatches\Command\SecurityCommand;
use BroCode\MagentoPatches\Command\StatusCommand;
use BroCode\MagentoPatches\Command\SyncCommand;
use BroCode\MagentoPatches\Command\UpgradeCommand;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

final class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [
            new StatusCommand(),
            new SecurityCommand(),
            new SyncCommand(),
            new QualityCommand(),
            new AddCommand(),
            new RemoveCommand(),
            new UpgradeCommand(),
        ];
    }
}
