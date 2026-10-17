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

namespace BroCode\MagentoPatches\Plan;

use BroCode\MagentoPatches\Patch\Stage;

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
