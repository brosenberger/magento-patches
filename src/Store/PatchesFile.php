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

namespace BroCode\MagentoPatches\Store;

use RuntimeException;

/**
 * patches.json as read by cweagans/composer-patches 2.x. Keeps unknown keys, foreign entries,
 * order and the per-package format (compact "description": "url" or expanded list).
 */
final class PatchesFile
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, int> $packageDepths extra.composer-patches.package-depths
     * @param int $defaultDepth extra.composer-patches.default-patch-depth
     */
    public function __construct(
        public readonly string $path,
        private readonly array $packageDepths = [],
        private readonly int $defaultDepth = 1,
    ) {
        $this->data = ['patches' => []];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('%s is not valid JSON: %s', $path, json_last_error_msg()));
            }
            $decoded['patches'] = is_array($decoded['patches'] ?? null) ? $decoded['patches'] : [];
            $this->data = $decoded;
        }
    }

    /** @return list<array{package: string, label: string, url: string, depth: int}> depth = what composer-patches will use */
    public function entries(): array
    {
        $out = [];
        foreach ($this->data['patches'] as $package => $defs) {
            foreach ($this->normalize((array) $defs) as [$label, $url, $depth]) {
                $out[] = ['package' => (string) $package, 'label' => $label, 'url' => $url, 'depth' => $depth ?? $this->depthOf((string) $package)];
            }
        }

        return $out;
    }

    /** Depth composer-patches applies to a package's entries that don't set their own. */
    public function depthOf(string $package): int
    {
        return (int) ($this->packageDepths[$package] ?? $this->defaultDepth);
    }

    public function has(string $package, string $label): bool
    {
        foreach ($this->normalize((array) ($this->data['patches'][$package] ?? [])) as [$existing]) {
            if ($existing === $label) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{package: string, label: string, url: string}> */
    public function managed(string $tag, ?string $id = null): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (array $e): bool => Label::matches($e['label'], $tag, $id)
        ));
    }

    /** @return string[] distinct ids of managed entries with this tag, in file order */
    public function ids(string $tag): array
    {
        $ids = [];
        foreach ($this->managed($tag) as $entry) {
            $ids[] = Label::parse($entry['label'])['id'];
        }

        return array_values(array_unique($ids));
    }

    public function add(string $package, string $label, string $url): void
    {
        $defs = $this->data['patches'][$package] ?? [];
        if ($this->depthOf($package) !== 1) {
            // Our patch files are package-relative (-p1). With package-depths / default-patch-depth set
            // to something else, composer-patches would apply them at that depth and fail — pin them.
            $defs = $this->expand($defs);
            foreach ($defs as $i => $def) {
                if (!isset($def['depth']) && Label::parse((string) ($def['description'] ?? '')) !== null) {
                    $defs[$i]['depth'] = 1;
                }
            }
            $defs[] = ['description' => $label, 'url' => $url, 'depth' => 1];
        } elseif ($this->isExpanded($defs)) {
            $defs[] = ['description' => $label, 'url' => $url];
        } else {
            $defs[$label] = $url;
        }
        $this->data['patches'][$package] = $defs;
    }

    public function remove(string $package, string $label): void
    {
        $defs = $this->data['patches'][$package] ?? [];
        if ($this->isExpanded($defs)) {
            $defs = array_values(array_filter($defs, static fn ($d) => ($d['description'] ?? null) !== $label));
        } else {
            unset($defs[$label]);
        }
        if ($defs === []) {
            unset($this->data['patches'][$package]);
        } else {
            $this->data['patches'][$package] = $defs;
        }
    }

    /**
     * Bulletins whose files needed no entry (everything already applied, or only for packages
     * that are not installed). Kept outside "patches", which is all composer-patches reads.
     */
    public function markCovered(string $id, string $note): void
    {
        $this->data['brocode-magento-patches']['covered'][\BroCode\MagentoPatches\Source\Bulletin::normalizeId($id)] = $note;
        ksort($this->data['brocode-magento-patches']['covered']);
    }

    public function isCovered(string $id): bool
    {
        return isset($this->data['brocode-magento-patches']['covered'][\BroCode\MagentoPatches\Source\Bulletin::normalizeId($id)]);
    }

    public function coveredNote(string $id): ?string
    {
        return $this->data['brocode-magento-patches']['covered'][\BroCode\MagentoPatches\Source\Bulletin::normalizeId($id)] ?? null;
    }

    public function unmarkCovered(string $id): void
    {
        unset($this->data['brocode-magento-patches']['covered'][\BroCode\MagentoPatches\Source\Bulletin::normalizeId($id)]);
        if (($this->data['brocode-magento-patches']['covered'] ?? null) === []) {
            unset($this->data['brocode-magento-patches']['covered']);
        }
        if (($this->data['brocode-magento-patches'] ?? null) === []) {
            unset($this->data['brocode-magento-patches']);
        }
    }

    /** Does patches.lock.json describe exactly these entries? (composer install applies the lock) */
    public function inSyncWith(string $lockFile): bool
    {
        $entries = $this->entries();
        if (!is_file($lockFile)) {
            return $entries === [];
        }
        $lock = json_decode((string) file_get_contents($lockFile), true);
        $locked = [];
        foreach ((array) ($lock['patches'] ?? []) as $package => $patches) {
            foreach ((array) $patches as $patch) {
                $locked[] = $package . "\0" . ($patch['description'] ?? '') . "\0" . ($patch['url'] ?? '');
            }
        }
        $current = array_map(static fn (array $e): string => $e['package'] . "\0" . $e['label'] . "\0" . $e['url'], $entries);
        sort($locked);
        sort($current);

        return $locked === $current;
    }

    /** Is this patch file still referenced by any entry? */
    public function references(string $url): bool
    {
        foreach ($this->entries() as $entry) {
            if ($entry['url'] === $url) {
                return true;
            }
        }

        return false;
    }

    public function encode(): string
    {
        $data = $this->data;
        if ($data['patches'] === []) {
            $data['patches'] = new \stdClass();
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return list<array{0: string, 1: string, 2: ?int}> label, url, explicit depth */
    private function normalize(array $defs): array
    {
        $out = [];
        foreach ($this->expand($defs) as $def) {
            $out[] = [(string) ($def['description'] ?? ''), (string) ($def['url'] ?? ''), isset($def['depth']) ? (int) $def['depth'] : null];
        }

        return $out;
    }

    /** Compact {"description": "url"} → expanded [{"description", "url"}], order kept. */
    private function expand(array $defs): array
    {
        if ($this->isExpanded($defs)) {
            return array_values($defs);
        }
        $out = [];
        foreach ($defs as $label => $url) {
            $out[] = is_array($url) ? ['description' => (string) $label] + $url : ['description' => (string) $label, 'url' => (string) $url];
        }

        return $out;
    }

    private function isExpanded(array $defs): bool
    {
        return isset($defs[0]) && is_array($defs[0]);
    }
}
