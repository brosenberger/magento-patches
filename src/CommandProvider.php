<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches;

use Brocode\MagentoPatches\Command\AddCommand;
use Brocode\MagentoPatches\Command\QualityCommand;
use Brocode\MagentoPatches\Command\RemoveCommand;
use Brocode\MagentoPatches\Command\SecurityCommand;
use Brocode\MagentoPatches\Command\StatusCommand;
use Brocode\MagentoPatches\Command\SyncCommand;
use Brocode\MagentoPatches\Command\UpgradeCommand;
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
