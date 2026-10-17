<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Source;

use Composer\IO\IOInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Decides what has to be fetched: the index at most every $maxAgeHours, each bulletin once.
 * Everything else comes from patches/security-bulletins.json.
 */
final class BulletinCatalog
{
    /** True when the index was due this run (daily, or --refresh) — drives the daily KB check. */
    public bool $indexRefreshed = false;

    /** @var string[] bulletins in range that are neither stored nor fetchable — their status is unknown */
    public array $unknown = [];

    public function __construct(
        private readonly BulletinStore $store,
        private readonly ?BulletinRepository $remote, // null = offline
        private readonly IOInterface $io,
    ) {
    }

    /**
     * @param string[] $onlyIds check just these (no index)
     * @return Bulletin[] stored bulletins in release order
     */
    public function bulletins(array $onlyIds, int $sinceYear, bool $refresh, int $maxAgeHours): array
    {
        $fetched = 0;
        if ($onlyIds !== []) {
            $ids = array_map([Bulletin::class, 'normalizeId'], $onlyIds);
        } else {
            $checked = $this->store->indexCheckedAt();
            $stale = $checked === null || $checked < new DateTimeImmutable(sprintf('-%d hours', max(0, $maxAgeHours)));
            if ($this->remote !== null && ($refresh || $stale)) {
                $this->indexRefreshed = true;
                try {
                    $this->store->setIndex($this->remote->index());
                } catch (Throwable $e) {
                    $this->io->writeError('<warning>Bulletin index: ' . $e->getMessage() . ' — using stored data.</warning>');
                }
            }
            $ids = array_values(array_filter(
                $this->store->knownIds(),
                static fn (string $id): bool => 2000 + intdiv(Bulletin::sortKey($id), 10000) >= $sinceYear
            ));
        }

        $result = [];
        foreach ($ids as $id) {
            if ($this->remote !== null && ($refresh || !$this->store->has($id))) {
                try {
                    $this->store->put($this->remote->bulletin($id));
                    $fetched++;
                } catch (Throwable $e) {
                    $this->io->writeError(sprintf('<warning>%s: %s</warning>', $id, $e->getMessage()));
                }
            }
            $bulletin = $this->store->get($id);
            if ($bulletin !== null) {
                $result[] = $bulletin;
                continue;
            }
            $this->unknown[] = $id;
            if ($this->remote === null) {
                $this->io->writeError(sprintf('<warning>%s: not in %s (offline)</warning>', $id, basename($this->store->path)));
            }
        }
        usort($result, static fn (Bulletin $a, Bulletin $b): int => $a->number() <=> $b->number());

        $saved = $this->store->save();
        $checked = $this->store->indexCheckedAt();
        $this->io->write(sprintf(
            'Bulletin data: %d stored in %s%s%s',
            $this->store->count(),
            basename(dirname($this->store->path)) . '/' . basename($this->store->path),
            $checked ? ', index checked ' . $checked->format('Y-m-d H:i') : ', index never checked',
            $fetched > 0 ? sprintf(', %d fetched now%s', $fetched, $saved ? ' (commit the file)' : '') : ($this->remote === null ? ' (offline)' : '')
        ));

        return $result;
    }
}
