<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace BroCode\MagentoPatches\Runtime;

use BroCode\MagentoPatches\Patch\DiffParser;
use BroCode\MagentoPatches\Patch\PackageLocator;
use BroCode\MagentoPatches\Patch\Stage;
use BroCode\MagentoPatches\Plan\PlannedPatch;
use BroCode\MagentoPatches\Store\PatchesFile;
use BroCode\MagentoPatches\Store\Transaction;
use Composer\IO\IOInterface;
use RuntimeException;
use Throwable;

/**
 * Writes the change set, lets composer-patches apply it (relock + reinstall of the affected
 * packages), then checks that vendor/ ended up byte-identical to the validated stage.
 * All file writes go through the caller's Transaction; the caller commits or rolls back.
 */
final class Applier
{
    public function __construct(
        private readonly IOInterface $io,
        private readonly ComposerRunner $composer,
        private readonly PatchesFile $store,
        private readonly PackageLocator $locator,
        private readonly string $root,
        private readonly string $lockFile,
    ) {
    }

    /**
     * @param PlannedPatch[] $add
     * @param list<array{package: string, label: string, url: string}> $remove
     * @param array<string, Stage> $stages
     * @return string[] packages whose patch set changed
     */
    public function apply(Transaction $tx, array $add, array $remove, array $stages = []): array
    {
        $affected = [];
        $touched = [];

        foreach ($remove as $entry) {
            $affected[$entry['package']] = true;
            foreach ($this->filesOf($this->root . '/' . $entry['url']) as $rel) {
                $touched[$entry['package']][$rel] = true;
            }
            $this->store->remove($entry['package'], $entry['label']);
        }
        foreach ($remove as $entry) {
            if (!$this->store->references($entry['url']) && is_file($this->root . '/' . $entry['url'])) {
                $tx->delete($this->root . '/' . $entry['url']);
            }
        }
        foreach ($add as $patch) {
            $tx->write($this->root . '/' . $patch->path, $patch->content);
            $this->store->add($patch->package, $patch->label, $patch->path);
            $affected[$patch->package] = true;
            foreach ($patch->files as $rel) {
                $touched[$patch->package][$rel] = true;
            }
        }
        if ($affected === []) {
            return [];
        }
        $tx->write($this->store->path, $this->store->encode());
        $tx->track($this->lockFile);

        $deployed = $this->snapshotDeployed($tx, $touched);

        $this->composer->run(['patches-relock'], true);
        $this->reinstall(array_keys($affected));
        $this->verify($stages);
        $this->syncDeployed($tx, $deployed);

        return array_keys($affected);
    }

    /** After a rollback of the files: bring vendor/ back in line with the restored patch set. */
    public function restore(array $packages, bool $install = false): void
    {
        try {
            if ($install) {
                $this->composer->run(['install']);
            }
            $this->composer->run(['patches-relock'], true);
            $this->reinstall($packages);
            $this->io->writeError('<info>Rolled back: patches.json, patches.lock.json and vendor/ are back to the previous state.</info>');
        } catch (Throwable $e) {
            $this->io->writeError(sprintf(
                "<error>Rollback of the files succeeded, but restoring vendor/ failed: %s</error>\n" .
                '<error>Run `composer patches-relock && composer patches-repatch` manually.</error>',
                $e->getMessage()
            ));
        }
    }

    /** @param string[] $packages */
    private function reinstall(array $packages): void
    {
        $installed = array_values(array_filter($packages, fn (string $p): bool => $this->locator->installPathOf($p) !== null));
        if ($installed !== []) {
            // Only the package files matter here; composer-patches applies through plugin events, not
            // scripts. Project post-install scripts (copying configs, building assets) may fail or
            // have side effects on a reinstall and would roll back a valid patch set.
            $this->composer->run(array_merge(['reinstall', '--no-scripts'], $installed));
        }
    }

    /** @param array<string, Stage> $stages */
    private function verify(array $stages): void
    {
        $problems = [];
        foreach ($stages as $package => $stage) {
            foreach ($stage->changedFiles() as $rel => $expected) {
                $file = $stage->installPath . '/' . $rel;
                $actual = is_file($file) ? file_get_contents($file) : null;
                if ($actual !== $expected) {
                    $problems[] = sprintf('%s:%s', $package, $rel);
                }
            }
        }
        if ($problems !== []) {
            throw new RuntimeException("After applying, these files differ from the validated result:\n  - " . implode("\n  - ", $problems));
        }
    }

    /**
     * Root copies of mapped files (lib/web, setup/src, app/etc …) before the reinstall.
     *
     * @param array<string, array<string, true>> $touched
     * @return list<array{target: string, vendor: string, vendorBefore: ?string}>
     */
    private function snapshotDeployed(Transaction $tx, array $touched): array
    {
        $deployed = [];
        foreach ($touched as $package => $files) {
            $installPath = $this->locator->installPathOf($package);
            if ($installPath === null) {
                continue;
            }
            foreach (array_keys($files) as $rel) {
                foreach ($this->locator->rootTargets($package, $rel) as $target) {
                    $tx->track($this->root . '/' . $target);
                    $vendor = $installPath . '/' . $rel;
                    $deployed[] = [
                        'target' => $target,
                        'vendor' => $vendor,
                        'vendorBefore' => is_file($vendor) ? (string) file_get_contents($vendor) : null,
                    ];
                }
            }
        }

        return $deployed;
    }

    /**
     * Magento's composer installer deploys mapped files on install; if a root copy is still the
     * old vendor content (not deployed, unmodified), update it. Locally modified copies are reported.
     *
     * @param list<array{target: string, vendor: string, vendorBefore: ?string}> $deployed
     */
    private function syncDeployed(Transaction $tx, array $deployed): void
    {
        foreach ($deployed as $item) {
            $rootFile = $this->root . '/' . $item['target'];
            if (is_link($rootFile)) {
                continue;
            }
            $now = is_file($item['vendor']) ? (string) file_get_contents($item['vendor']) : null;
            $root = is_file($rootFile) ? (string) file_get_contents($rootFile) : null;
            if ($root === $now) {
                continue;
            }
            if ($root === $item['vendorBefore']) {
                if ($now === null) {
                    $tx->delete($rootFile);
                } else {
                    $tx->write($rootFile, $now);
                }
                $this->io->write(sprintf('  - synced deployed copy <info>%s</info>', $item['target']));
            } else {
                $this->io->writeError(sprintf('  <warning>%s differs from the patched package file (locally modified?) — left untouched.</warning>', $item['target']));
            }
        }
    }

    /** @return string[] package-relative files changed by a stored package patch */
    private function filesOf(string $patchFile): array
    {
        if (!is_file($patchFile)) {
            return [];
        }
        try {
            $files = [];
            foreach ((new DiffParser())->parse((string) file_get_contents($patchFile)) as $chunk) {
                array_push($files, ...$chunk->paths());
            }

            return array_values(array_unique($files));
        } catch (Throwable) {
            return [];
        }
    }
}
