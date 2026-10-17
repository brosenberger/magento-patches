<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Runtime;

use BroCode\MagentoPatches\Magento\MagentoInfo;
use BroCode\MagentoPatches\Patch\PackageLocator;
use BroCode\MagentoPatches\Patch\Pristine;
use BroCode\MagentoPatches\Patch\Validator;
use BroCode\MagentoPatches\Plan\Planner;
use BroCode\MagentoPatches\Store\Downloads;
use BroCode\MagentoPatches\Store\PatchesFile;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

/** Wires the services for one command run against the current Composer state. */
final class Context
{
    public readonly MagentoInfo $info;
    public readonly PackageLocator $locator;
    public readonly PatchesFile $store;
    public readonly ProcessExecutor $process;
    public readonly ComposerRunner $runner;
    public readonly Applier $applier;
    public readonly Planner $planner;
    public readonly string $root;
    public readonly string $lockFile;
    public readonly Downloads $downloads;
    public readonly string $bulletinsFile;

    public function __construct(
        public readonly Composer $composer,
        public readonly IOInterface $io,
        public readonly string $workDir,
        bool $strict = false,
        bool $skipUnsupported = false,
    ) {
        $this->info = new MagentoInfo($composer);
        $this->root = $this->info->root();
        $this->locator = new PackageLocator($this->info);
        $this->store = new PatchesFile($this->root . '/' . self::patchesFileName($composer));
        $this->lockFile = self::patchesLockFile();
        $settings = (array) ($composer->getPackage()->getExtra()['brocode-magento-patches'] ?? []);
        $this->downloads = new Downloads($this->root . '/' . trim((string) ($settings['downloads-dir'] ?? 'patches/downloads'), '/'));
        $this->bulletinsFile = $this->root . '/' . trim((string) ($settings['bulletins-file'] ?? 'patches/security-bulletins.json'), '/');
        $this->process = new ProcessExecutor($io);
        $this->runner = new ComposerRunner($io, $this->root);
        $this->applier = new Applier($io, $this->runner, $this->store, $this->locator, $this->root, $this->lockFile);
        (new Filesystem())->ensureDirectoryExists($workDir);
        $this->planner = new Planner(
            $this->locator,
            new Validator($this->process, $workDir . '/validate', $strict),
            $this->store,
            $this->process,
            $workDir . '/stage',
            $this->root,
            'patches',
            $skipUnsupported,
            new Pristine($composer, $this->info, $this->process, $workDir . '/pristine'),
        );
    }

    /** Same setting composer-patches 2.x reads: extra.composer-patches.patches-file, default patches.json. */
    public static function patchesFileName(Composer $composer): string
    {
        $extra = $composer->getPackage()->getExtra();

        return (string) ($extra['composer-patches']['patches-file'] ?? 'patches.json');
    }

    /** Same rule as composer-patches: composer.json → patches.lock.json, foo.json → foo-patches.lock.json. */
    public static function patchesLockFile(): string
    {
        $composerFile = Factory::getComposerFile();
        $dir = dirname((string) realpath($composerFile));
        $base = pathinfo($composerFile, PATHINFO_FILENAME);

        return $base === 'composer' ? "$dir/patches.lock.json" : "$dir/$base-patches.lock.json";
    }

    public function composerFiles(): array
    {
        $composerFile = (string) realpath(Factory::getComposerFile());

        return [$composerFile, Factory::getLockFile($composerFile)];
    }
}
