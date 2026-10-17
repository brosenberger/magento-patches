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

namespace BroCode\MagentoPatches\Patch;

use BroCode\MagentoPatches\Magento\MagentoInfo;

/**
 * Maps a project-relative file path (as used in Adobe/QPT patches) to the installed
 * Composer package that owns it, using the real install paths Composer reports.
 *
 *  vendor/magento/module-email/Model/Transport.php  → magento/module-email : Model/Transport.php
 *  lib/web/mage/menu.js                              → magento/magento2-base : lib/web/mage/menu.js (deployed via "map")
 *  vendor/magento/module-company/...  (CE project)   → not installed, skipped
 */
final class PackageLocator
{
    /** @var array<string, array{name: string, path: string}> project-relative install path => package */
    private array $installed = [];

    /** @var list<array{target: string, source: string, name: string, path: string}> */
    private array $maps = [];

    private string $vendorRel;

    public function __construct(private readonly MagentoInfo $info)
    {
        foreach ($info->packages() as $package) {
            $abs = $info->installPath($package);
            if ($abs === null || !is_dir($abs)) {
                continue; // metapackages
            }
            $rel = $info->relative($abs);
            $this->installed[$rel] = ['name' => $package->getName(), 'path' => $abs];

            foreach ((array) ($package->getExtra()['map'] ?? []) as $entry) {
                if (is_array($entry) && isset($entry[0], $entry[1])) {
                    $this->maps[] = [
                        'target' => trim(str_replace('\\', '/', (string) $entry[1]), '/'),
                        'source' => trim(str_replace('\\', '/', (string) $entry[0]), '/'),
                        'name' => $package->getName(),
                        'path' => $abs,
                    ];
                }
            }
        }
        // Longest (most specific) paths first.
        uksort($this->installed, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        usort($this->maps, static fn (array $a, array $b): int => strlen($b['target']) <=> strlen($a['target']));
        $this->vendorRel = $info->relative($info->vendorDir());
    }

    public function locate(string $projectPath): Location
    {
        $path = ltrim(str_replace('\\', '/', $projectPath), '/');
        if ($this->vendorRel !== 'vendor' && str_starts_with($path, 'vendor/')) {
            // Adobe patches always say vendor/…; follow a customized vendor-dir.
            $path = $this->vendorRel . substr($path, strlen('vendor'));
        }

        foreach ($this->installed as $rel => $pkg) {
            if (str_starts_with($path, $rel . '/')) {
                return new Location(Location::PACKAGE, $pkg['name'], $pkg['path'], substr($path, strlen($rel) + 1));
            }
        }

        if ($path === $this->vendorRel . '/bin/patch-status') {
            // Adobe's monthly security files add/update their versioning tool here. It belongs to no
            // package (vendor/bin is generated), so composer-patches cannot apply it, and it fixes nothing.
            return new Location(Location::IGNORED, reason: "Adobe's patch-status tool, not part of the fix (vendor/bin belongs to no package)");
        }

        if (str_starts_with($path, $this->vendorRel . '/')) {
            $parts = explode('/', substr($path, strlen($this->vendorRel) + 1));
            if (count($parts) >= 3) {
                return new Location(Location::NOT_INSTALLED, $parts[0] . '/' . $parts[1], reason: 'package not installed');
            }

            return new Location(Location::UNSUPPORTED, reason: 'file in vendor/ outside any package');
        }

        foreach ($this->maps as $map) {
            if ($path === $map['target'] || str_starts_with($path, $map['target'] . '/')) {
                $rest = substr($path, strlen($map['target']));

                return new Location(Location::MAPPED, $map['name'], $map['path'], ltrim($map['source'] . $rest, '/'));
            }
        }

        return new Location(Location::UNSUPPORTED, reason: 'project file that no installed package provides');
    }

    /** Location for a path that is already relative to the given package (stored package patches). */
    public function inPackage(string $packageName, string $relPath): Location
    {
        foreach ($this->installed as $pkg) {
            if ($pkg['name'] === $packageName) {
                return new Location(Location::PACKAGE, $packageName, $pkg['path'], $relPath);
            }
        }

        return new Location(Location::NOT_INSTALLED, $packageName, reason: 'package not installed');
    }

    /**
     * Project-relative root copies of a package file deployed through the package's "map"
     * (magento2-base: lib/web, setup/src, app/etc/di.xml …).
     *
     * @return string[]
     */
    public function rootTargets(string $packageName, string $relPath): array
    {
        $targets = [];
        foreach ($this->maps as $map) {
            if ($map['name'] !== $packageName) {
                continue;
            }
            if ($relPath === $map['source'] || str_starts_with($relPath, $map['source'] . '/')) {
                $targets[] = ltrim($map['target'] . substr($relPath, strlen($map['source'])), '/');
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Root copies a package deploys through its "map" (absolute source file => project-relative target),
     * with the package-relative source path, so callers can tell patched files from untouched ones.
     *
     * @return list<array{source: string, rel: string, target: string}>
     */
    public function deployedFiles(string $packageName): array
    {
        $files = [];
        foreach ($this->maps as $map) {
            if ($map['name'] !== $packageName) {
                continue;
            }
            $from = $map['path'] . '/' . $map['source'];
            if (is_file($from)) {
                $files[] = ['source' => $from, 'rel' => $map['source'], 'target' => $map['target']];
                continue;
            }
            if (!is_dir($from)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $sub = substr((string) $file, strlen($from));
                $files[] = ['source' => (string) $file, 'rel' => $map['source'] . $sub, 'target' => $map['target'] . $sub];
            }
        }

        return $files;
    }

    public function installPathOf(string $packageName): ?string
    {
        foreach ($this->installed as $pkg) {
            if ($pkg['name'] === $packageName) {
                return $pkg['path'];
            }
        }

        return null;
    }
}
