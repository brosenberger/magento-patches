<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Magento;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Magento release version as used by Composer and by Adobe's bulletins:
 *   2.4.7, 2.4.7-p10, 2.4.9-beta1, 2.4.9-alpha3, 2.4.9-rc2 and monthly builds like 2.4.7-2026-jul.
 * B2B versions (1.5.2-p5) follow the same grammar.
 */
final class MagentoVersion
{
    private const STAGES = ['alpha' => 0, 'beta' => 1, 'rc' => 2];
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** Regex fragment matching one version token, shared with the bulletin parser. */
    public const TOKEN = '\d+\.\d+\.\d+(?:-(?:p\d+|alpha\d*|beta\d*|rc\d*|\d{4}-[a-z]{3}))?';

    private function __construct(
        public readonly string $raw,
        public readonly string $line,
        public readonly string $kind,      // pre | ga | patch | date
        public readonly int $number = 0,   // pN / pre-release number
        public readonly int $stage = 0,    // pre-release stage index
        public readonly ?int $year = null,
        public readonly ?int $month = null,
    ) {
    }

    public static function normalize(string $text): string
    {
        // Adobe writes versions with non-breaking hyphens (U+2011) and non-breaking spaces.
        return strtolower(str_replace(["\u{2011}", "\u{2010}", "\u{2013}", "\u{00A0}"], ['-', '-', '-', ' '], trim($text)));
    }

    public static function parse(string $version): ?self
    {
        $v = self::normalize($version);
        if (!preg_match('/^v?(\d+\.\d+\.\d+)(?:\.\d+)?(?:-(.+))?$/', $v, $m)) {
            return null;
        }
        $line = $m[1];
        $suffix = $m[2] ?? '';
        if ($suffix === '') {
            return new self($v, $line, 'ga');
        }
        if (preg_match('/^p(?:atch)?(\d+)$/', $suffix, $s)) {
            return new self($v, $line, 'patch', (int) $s[1]);
        }
        if (preg_match('/^(alpha|beta|rc)(\d*)$/', $suffix, $s)) {
            return new self($v, $line, 'pre', (int) ($s[2] ?: 0), self::STAGES[$s[1]]);
        }
        if (preg_match('/^(\d{4})-([a-z]{3})$/', $suffix, $s) && isset(self::MONTHS[$s[2]])) {
            return new self($v, $line, 'date', 0, 0, (int) $s[1], self::MONTHS[$s[2]]);
        }

        return null;
    }

    public function isDateBuild(): bool
    {
        return $this->kind === 'date';
    }

    /**
     * Is $this (installed) covered by an "<bound> and earlier" statement of a bulletin?
     *
     * Monthly builds (2.4.7-2026-aug) are not Composer versions; Adobe ships them as isolated
     * patch files on top of the latest -p. An installed -p release is therefore affected by a
     * monthly bound when it was released before the end of that month. Without a release date
     * we cannot decide and answer "affected" (fail safe).
     */
    public function isCoveredBy(self $bound, ?DateTimeInterface $installedReleaseDate): bool
    {
        if ($this->line !== $bound->line) {
            return version_compare($this->line, $bound->line, '<=');
        }
        if ($bound->isDateBuild()) {
            if ($this->isDateBuild()) {
                return [$this->year, $this->month] <= [$bound->year, $bound->month];
            }
            if ($installedReleaseDate === null) {
                return true;
            }
            $endOfMonth = (new DateTimeImmutable(sprintf('%04d-%02d-01', $bound->year, $bound->month)))->modify('+1 month');

            return $installedReleaseDate < $endOfMonth;
        }
        if ($this->isDateBuild()) {
            // A monthly build is newer than any -p released before it; a -p bound only covers it
            // if that -p is from the same or a later month, which bulletins never state. Fail safe.
            return true;
        }

        return $this->compareRank($bound) <= 0;
    }

    /** Ordering within one release line, for non-date versions. */
    public function compareRank(self $other): int
    {
        if ($this->line !== $other->line) {
            return version_compare($this->line, $other->line);
        }

        return $this->rank() <=> $other->rank();
    }

    /** @return array{int, int, int} */
    private function rank(): array
    {
        return match ($this->kind) {
            'pre' => [0, $this->stage, $this->number],
            'ga' => [1, 0, 0],
            'patch' => [2, $this->number, 0],
            default => [3, ($this->year ?? 0) * 100 + ($this->month ?? 0), 0],
        };
    }

    /**
     * Regex that finds this exact version inside a file name: 2.4.7-p10 / 2-4-7-p10 / 2_4_7_p10, and
     * the compact form of Adobe's isolated patch files (247p10-2026-07-001-CE.patch, VULN-39341_247-p10.patch);
     * not -p1 for -p10.
     */
    public function fileNamePattern(): string
    {
        $parts = preg_split('/[.\-]/', $this->raw) ?: [];
        $quoted = array_map(static fn (string $p): string => preg_quote($p, '#'), $parts);
        $dotted = '(?<![0-9])' . implode('[._-]', $quoted);
        $compact = '(?<![0-9a-z])' . implode('', array_slice($quoted, 0, 3)) . ($this->kind === 'ga' ? '' : '[._-]?' . implode('[._-]', array_slice($quoted, 3)));

        // A plain 2.4.7 must not match 2.4.7-p10 or the monthly build 2.4.7-2026-jul (but does match 247-2026-07-001).
        return '#(?:' . $dotted . '|' . $compact . ')(?![0-9a-z]|[._-](?:p\d|alpha|beta|rc|\d{4}[._-][a-z]{3}))#i';
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
