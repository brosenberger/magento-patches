<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Source;

use DateTimeImmutable;
use RuntimeException;

/**
 * patches/security-bulletins.json — the parsed bulletins, kept in the project so they are
 * committed, readable without tooling, usable offline (CI) and fetched only once.
 */
final class BulletinStore
{
    /** @var array<string, mixed> */
    private array $data = ['index' => ['url' => BulletinRepository::INDEX_URL, 'checkedAt' => null, 'ids' => []], 'bulletins' => []];
    private bool $dirty = false;

    public function __construct(public readonly string $path)
    {
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('%s is not valid JSON: %s', $path, json_last_error_msg()));
            }
            $this->data = array_replace($this->data, $decoded);
        }
    }

    public function indexCheckedAt(): ?DateTimeImmutable
    {
        $at = $this->data['index']['checkedAt'] ?? null;

        return is_string($at) ? new DateTimeImmutable($at) : null;
    }

    /** @return string[] */
    public function indexIds(): array
    {
        return array_values((array) ($this->data['index']['ids'] ?? []));
    }

    /** @param string[] $ids */
    public function setIndex(array $ids): void
    {
        $ids = array_values(array_unique(array_map([Bulletin::class, 'normalizeId'], array_merge($this->indexIds(), $ids))));
        usort($ids, static fn (string $a, string $b): int => Bulletin::sortKey($a) <=> Bulletin::sortKey($b));
        $this->data['index']['ids'] = $ids;
        $this->data['index']['checkedAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $this->dirty = true;
    }

    public function has(string $id): bool
    {
        return isset($this->data['bulletins'][Bulletin::normalizeId($id)]);
    }

    public function get(string $id): ?Bulletin
    {
        $row = $this->data['bulletins'][Bulletin::normalizeId($id)] ?? null;

        return is_array($row) ? Bulletin::fromArray($id, $row) : null;
    }

    public function fetchedAt(string $id): ?string
    {
        return $this->data['bulletins'][Bulletin::normalizeId($id)]['fetchedAt'] ?? null;
    }

    public function put(Bulletin $bulletin): void
    {
        $row = $bulletin->toArray() + ['fetchedAt' => (new DateTimeImmutable())->format(DATE_ATOM)];
        $old = $this->data['bulletins'][$bulletin->id] ?? null;
        if (is_array($old)) {
            unset($old['fetchedAt']);
            $compare = $row;
            unset($compare['fetchedAt']);
            if ($old === $compare) {
                return; // unchanged — keep the file stable
            }
        }
        $this->data['bulletins'][$bulletin->id] = $row;
        $this->dirty = true;
    }

    /** @return string[] ids of all stored bulletins plus those known from the index, oldest first */
    public function knownIds(): array
    {
        $ids = array_values(array_unique(array_merge($this->indexIds(), array_keys((array) $this->data['bulletins']))));
        usort($ids, static fn (string $a, string $b): int => Bulletin::sortKey($a) <=> Bulletin::sortKey($b));

        return $ids;
    }

    public function count(): int
    {
        return count((array) $this->data['bulletins']);
    }

    public function save(): bool
    {
        if (!$this->dirty) {
            return false;
        }
        $bulletins = (array) $this->data['bulletins'];
        uksort($bulletins, static fn (string $a, string $b): int => Bulletin::sortKey($a) <=> Bulletin::sortKey($b));
        $data = [
            '_readme' => 'Adobe Commerce security bulletins parsed by brocode/magento-patches. Update: composer patches:security (--refresh to re-read all). Commit this file.',
            'index' => $this->data['index'],
            'bulletins' => $bulletins === [] ? new \stdClass() : $bulletins,
        ];
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $this->dirty = false;

        return true;
    }
}
