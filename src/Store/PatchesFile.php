<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Store;

use RuntimeException;

/**
 * patches.json as read by cweagans/composer-patches 2.x. Keeps unknown keys, foreign entries,
 * order and the per-package format (compact "description": "url" or expanded list).
 */
final class PatchesFile
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(public readonly string $path)
    {
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

    /** @return list<array{package: string, label: string, url: string}> */
    public function entries(): array
    {
        $out = [];
        foreach ($this->data['patches'] as $package => $defs) {
            foreach ($this->normalize((array) $defs) as [$label, $url]) {
                $out[] = ['package' => (string) $package, 'label' => $label, 'url' => $url];
            }
        }

        return $out;
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
        if ($this->isExpanded($defs)) {
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
        $this->data['brocode-magento-patches']['covered'][\Brocode\MagentoPatches\Source\Bulletin::normalizeId($id)] = $note;
        ksort($this->data['brocode-magento-patches']['covered']);
    }

    public function isCovered(string $id): bool
    {
        return isset($this->data['brocode-magento-patches']['covered'][\Brocode\MagentoPatches\Source\Bulletin::normalizeId($id)]);
    }

    public function coveredNote(string $id): ?string
    {
        return $this->data['brocode-magento-patches']['covered'][\Brocode\MagentoPatches\Source\Bulletin::normalizeId($id)] ?? null;
    }

    public function unmarkCovered(string $id): void
    {
        unset($this->data['brocode-magento-patches']['covered'][\Brocode\MagentoPatches\Source\Bulletin::normalizeId($id)]);
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

    /** @return list<array{0: string, 1: string}> */
    private function normalize(array $defs): array
    {
        $out = [];
        if ($this->isExpanded($defs)) {
            foreach ($defs as $def) {
                $out[] = [(string) ($def['description'] ?? ''), (string) ($def['url'] ?? '')];
            }

            return $out;
        }
        foreach ($defs as $label => $url) {
            $out[] = [(string) $label, is_array($url) ? (string) ($url['url'] ?? '') : (string) $url];
        }

        return $out;
    }

    private function isExpanded(array $defs): bool
    {
        return isset($defs[0]) && is_array($defs[0]);
    }
}
