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

namespace BroCode\MagentoPatches\Source;

use BroCode\MagentoPatches\Magento\MagentoInfo;
use BroCode\MagentoPatches\Magento\MagentoVersion;
use BroCode\MagentoPatches\Plan\Plan;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use RuntimeException;

/**
 * Reads Adobe's Quality Patches Tool registry (support-patches.json / community-patches.json)
 * and picks, per patch ID, the file whose constraint matches the *installed* package versions.
 *
 * Registry shape:
 *   { "ACSD-123": { "title": "...", "packages": { "magento/magento2-base": {
 *       ">=2.4.4 <2.4.7-p1": { "file": "os/ACSD-123_2.4.4.patch", "require": [...], "replaced-with": "...", "deprecated": true }
 *   } } } }
 */
final class QualityPatches
{
    /** @var array<string, array<string, mixed>> upper-case id => entry */
    private array $registry = [];
    private VersionParser $parser;

    public function __construct(public readonly string $path)
    {
        foreach (['support-patches.json', 'community-patches.json'] as $file) {
            if (!is_file($path . '/' . $file)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path . '/' . $file), true);
            foreach (is_array($data) ? $data : [] as $id => $entry) {
                $this->registry[strtoupper((string) $id)] = (array) $entry + ['id' => (string) $id];
            }
        }
        if ($this->registry === []) {
            throw new RuntimeException(sprintf('No Quality Patches registry found in %s.', $path));
        }
        $this->parser = new VersionParser();
    }

    public static function locate(MagentoInfo $info, ?string $override): self
    {
        if ($override !== null && $override !== '') {
            return new self(rtrim($override, '/'));
        }
        $package = $info->package('magento/quality-patches');
        $path = $package ? $info->installPath($package) : null;
        if ($path === null) {
            throw new RuntimeException(
                'magento/quality-patches is not installed. Run `composer require --dev magento/quality-patches` ' .
                'or pass --qpt-path=<checkout of github.com/magento/quality-patches>.'
            );
        }

        return new self($path);
    }

    public function version(): string
    {
        $info = json_decode((string) @file_get_contents($this->path . '/patches-info.json'), true);

        return (string) ($info['version'] ?? json_decode((string) @file_get_contents($this->path . '/composer.json'), true)['version'] ?? 'unknown');
    }

    /** Latest -p release of a line according to QPT's release list, e.g. 2.4.7 → 2.4.7-p10. */
    public function latestRelease(string $line): ?MagentoVersion
    {
        $releases = json_decode((string) @file_get_contents($this->path . '/magento_releases.json'), true);
        $latest = null;
        foreach (array_keys(is_array($releases) ? $releases : []) as $release) {
            $v = MagentoVersion::parse((string) $release);
            if ($v !== null && $v->line === $line && $v->kind !== 'pre' && ($latest === null || $v->compareRank($latest) > 0)) {
                $latest = $v;
            }
        }

        return $latest;
    }

    /**
     * @param string[] $ids
     * @return list<array{id: string, title: string, file: string}>
     */
    public function resolve(array $ids, MagentoInfo $info, Plan $plan, bool $allowDeprecated = false): array
    {
        $resolved = [];
        $seen = [];
        foreach ($ids as $id) {
            $this->resolveOne(strtoupper(trim($id)), $info, $plan, $allowDeprecated, $resolved, $seen, null);
        }

        return $resolved;
    }

    /**
     * @param list<array{id: string, title: string, file: string}> $resolved
     * @param array<string, true> $seen
     */
    private function resolveOne(string $id, MagentoInfo $info, Plan $plan, bool $allowDeprecated, array &$resolved, array &$seen, ?string $requiredBy): void
    {
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $entry = $this->registry[$id] ?? null;
        if ($entry === null) {
            $plan->conflicts[] = sprintf('%s: unknown patch id (QPT %s)', $id, $this->version());

            return;
        }
        $title = (string) ($entry['title'] ?? '');
        $files = [];
        $handled = false; // replaced or deprecated: no "no file" note
        foreach ((array) ($entry['packages'] ?? []) as $anchor => $constraints) {
            $package = $info->package((string) $anchor);
            if ($package === null) {
                continue;
            }
            $installed = new Constraint('==', $package->getVersion());
            foreach ((array) $constraints as $constraint => $meta) {
                try {
                    $matches = $this->parser->parseConstraints((string) $constraint)->matches($installed);
                } catch (\UnexpectedValueException) {
                    $matches = false;
                }
                if (!$matches) {
                    continue;
                }
                if (!empty($meta['deprecated']) && !$allowDeprecated) {
                    $plan->note('warn', sprintf('%s: deprecated for %s %s — skipped (--allow-deprecated to force)', $entry['id'], $anchor, $package->getPrettyVersion()));
                    $handled = true;
                    continue 2;
                }
                if (!empty($meta['replaced-with'])) {
                    $handled = true;
                    $replacement = strtoupper((string) $meta['replaced-with']);
                    $plan->note('info', sprintf('%s is replaced by %s for %s %s', $entry['id'], $replacement, $anchor, $package->getPrettyVersion()));
                    $this->resolveOne($replacement, $info, $plan, $allowDeprecated, $resolved, $seen, $requiredBy);
                    continue 2;
                }
                foreach ((array) ($meta['require'] ?? []) as $required) {
                    $this->resolveOne(strtoupper((string) $required), $info, $plan, $allowDeprecated, $resolved, $seen, $entry['id']);
                }
                if (!empty($meta['file'])) {
                    $files[] = $this->path . '/patches/' . $meta['file'];
                }
                break; // first matching constraint per anchor package
            }
        }
        foreach ((array) ($entry['requirements'] ?? $entry['requirement'] ?? []) as $requirement) {
            $plan->note('warn', sprintf('%s: %s', $entry['id'], $requirement));
        }
        if ($files === []) {
            if (!$handled) {
                $plan->note('skip', sprintf('%s: no patch file for the installed versions (%s) — not needed or not available', $entry['id'], $info->describe()));
            }

            return;
        }
        if ($requiredBy !== null) {
            $plan->note('info', sprintf('%s is required by %s — added first', $entry['id'], $requiredBy));
        }
        foreach (array_unique($files) as $file) {
            if (!is_file($file)) {
                $plan->conflicts[] = sprintf('%s: registry points to missing file %s', $entry['id'], $file);
                continue;
            }
            $resolved[] = ['id' => (string) $entry['id'], 'title' => $title, 'file' => $file];
        }
    }
}
