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

use BroCode\MagentoPatches\Magento\MagentoVersion;

final class Bulletin
{
    /**
     * @param array<string, list<array{version: MagentoVersion, andEarlier: bool}>> $affected product => tokens
     * @param array<string, array{tokens: list<array{version: MagentoVersion, for: ?MagentoVersion}>, text: string, links: string[]}> $solution
     * @param string[] $cves
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly ?string $date,
        public readonly ?string $priority,
        public readonly array $affected,
        public readonly array $solution,
        public readonly array $cves,
        public readonly bool $exploited,
    ) {
    }

    /** Highest "… and earlier" bound for a product and release line. */
    public function affectedBound(string $product, string $line): ?MagentoVersion
    {
        $bound = null;
        foreach ($this->affected[$product] ?? [] as $token) {
            $v = $token['version'];
            if ($v->line !== $line) {
                continue;
            }
            if ($bound === null || $this->later($v, $bound)) {
                $bound = $v;
            }
        }

        return $bound;
    }

    /** The updated version Adobe names for this product and line (null = hotfix without version). */
    public function solutionFor(string $product, string $line): ?MagentoVersion
    {
        foreach ($this->solution[$product]['tokens'] ?? [] as $token) {
            if ($token['version']->line === $line) {
                return $token['version'];
            }
        }

        return null;
    }

    /** @return string[] */
    public function linksFor(string $product): array
    {
        return $this->solution[$product]['links'] ?? [];
    }

    public function solutionText(string $product): string
    {
        return $this->solution[$product]['text'] ?? '';
    }

    public function number(): int
    {
        return self::sortKey($this->id);
    }

    /** Plain data for patches/security-bulletins.json — versions as Adobe writes them. */
    public function toArray(): array
    {
        $affected = [];
        foreach ($this->affected as $product => $tokens) {
            $affected[$product] = array_map(
                static fn (array $t): string => $t['version'] . ($t['andEarlier'] ? ' and earlier' : ''),
                $tokens
            );
        }
        $solution = [];
        foreach ($this->solution as $product => $entry) {
            $solution[$product] = [
                'versions' => array_map(
                    static fn (array $t): string => $t['version'] . ($t['for'] !== null ? ' for ' . $t['for'] : ''),
                    $entry['tokens']
                ),
                'text' => $entry['text'],
                'links' => $entry['links'],
            ];
        }

        return [
            'url' => $this->url,
            'date' => $this->date,
            'priority' => $this->priority,
            'exploited' => $this->exploited,
            'cves' => $this->cves,
            'affected' => $affected,
            'solution' => $solution,
        ];
    }

    public static function fromArray(string $id, array $data): self
    {
        $affected = [];
        foreach ((array) ($data['affected'] ?? []) as $product => $tokens) {
            foreach ((array) $tokens as $token) {
                if (preg_match('/^(\S+)(\s+and\s+earlier)?$/i', trim((string) $token), $m) && ($v = MagentoVersion::parse($m[1])) !== null) {
                    $affected[$product][] = ['version' => $v, 'andEarlier' => !empty($m[2])];
                }
            }
        }
        $solution = [];
        foreach ((array) ($data['solution'] ?? []) as $product => $entry) {
            $tokens = [];
            foreach ((array) ($entry['versions'] ?? []) as $token) {
                if (preg_match('/^(\S+)(?:\s+for\s+(\S+))?/i', trim((string) $token), $m) && ($v = MagentoVersion::parse($m[1])) !== null) {
                    $tokens[] = ['version' => $v, 'for' => isset($m[2]) ? MagentoVersion::parse($m[2]) : null];
                }
            }
            $solution[$product] = ['tokens' => $tokens, 'text' => (string) ($entry['text'] ?? ''), 'links' => array_values((array) ($entry['links'] ?? []))];
        }

        return new self(
            strtoupper($id),
            (string) ($data['url'] ?? ''),
            isset($data['date']) ? (string) $data['date'] : null,
            isset($data['priority']) ? (string) $data['priority'] : null,
            $affected,
            $solution,
            array_values((array) ($data['cves'] ?? [])),
            (bool) ($data['exploited'] ?? false),
        );
    }

    /** APSB26-5 / apsb26-05 → APSB26-05 (Adobe's own spelling); anything else upper-cased. */
    public static function normalizeId(string $id): string
    {
        return preg_match('/^APSB(\d{2})-(\d+)$/i', trim($id), $m) ? sprintf('APSB%s-%02d', $m[1], (int) $m[2]) : strtoupper(trim($id));
    }

    public static function sortKey(string $id): int
    {
        return preg_match('/APSB(\d+)-(\d+)/i', $id, $m) ? (int) $m[1] * 10000 + (int) $m[2] : 0;
    }

    private function later(MagentoVersion $a, MagentoVersion $b): bool
    {
        if ($a->isDateBuild() !== $b->isDateBuild()) {
            return $a->isDateBuild();
        }

        return $a->compareRank($b) > 0;
    }
}
