<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * The plugin only contributes commands. Applying patches is left entirely to
 * cweagans/composer-patches 2.x, which reads patches.json / patches.lock.json on
 * every `composer install` — so the project needs no scripts entries at all.
 */
final class Plugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }
}
