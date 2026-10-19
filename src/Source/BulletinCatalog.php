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

use Composer\IO\IOInterface;
use Throwable;

/**
 * Decides what has to be fetched: the index only with --refresh (or once, when none is stored), each bulletin once.
 * Everything else comes from patches/security-bulletins.json.
 */
final class BulletinCatalog
{
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
    public function bulletins(array $onlyIds, int $sinceYear, bool $refresh): array
    {
        $fetched = 0;
        if ($onlyIds !== []) {
            $ids = array_map([Bulletin::class, 'normalizeId'], $onlyIds);
        } else {
            // Online only when asked (--refresh); a run without stored index fetches it once.
            if ($this->remote !== null && ($refresh || $this->store->indexCheckedAt() === null)) {
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
            $this->unknown[] = $id; // reported once, with the way out, by the caller
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
