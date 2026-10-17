<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Plan;

use Brocode\MagentoPatches\Patch\Stage;

final class Plan
{
    /** @var PlannedPatch[] */
    public array $patches = [];

    /** @var string[] */
    public array $conflicts = [];

    /** @var list<array{0: string, 1: string}> [level (info|skip|warn), text] */
    public array $messages = [];

    /** @var array<string, Stage> */
    public array $stages = [];

    public function note(string $level, string $text): void
    {
        $this->messages[] = [$level, $text];
    }

    public function isEmpty(): bool
    {
        return $this->patches === [];
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** @return string[] */
    public function packages(): array
    {
        return array_values(array_unique(array_map(static fn (PlannedPatch $p): string => $p->package, $this->patches)));
    }
}
