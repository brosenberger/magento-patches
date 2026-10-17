<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Patch;

final class Verdict
{
    public const APPLY = 'apply';
    public const ALREADY_APPLIED = 'already-applied';   // exact change is in the file already
    public const ALREADY_PRESENT = 'already-present';   // all declarations it adds already exist elsewhere
    public const CONFLICT = 'conflict';

    /** @param string[] $notes */
    public function __construct(
        public readonly string $fit,
        public readonly string $reason = '',
        public readonly array $notes = [],
    ) {
    }

    public static function apply(array $notes = []): self
    {
        return new self(self::APPLY, '', $notes);
    }

    public static function alreadyApplied(string $reason = 'already applied'): self
    {
        return new self(self::ALREADY_APPLIED, $reason);
    }

    public static function alreadyPresent(string $reason): self
    {
        return new self(self::ALREADY_PRESENT, $reason);
    }

    public static function conflict(string $reason): self
    {
        return new self(self::CONFLICT, $reason);
    }

    public function is(string $fit): bool
    {
        return $this->fit === $fit;
    }
}
