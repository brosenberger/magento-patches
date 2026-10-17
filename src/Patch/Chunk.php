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

namespace BroCode\MagentoPatches\Patch;

/**
 * The part of a patch that touches exactly one file.
 * Paths are already stripped of their a/ b/ prefix; null means /dev/null.
 */
final class Chunk
{
    /**
     * @param string[] $extended extended git header lines (mode, index, similarity …), without EOL
     * @param Hunk[] $hunks
     */
    public function __construct(
        public readonly ?string $oldPath,
        public readonly ?string $newPath,
        public readonly array $extended,
        public readonly array $hunks,
        public readonly bool $binary = false,
        public readonly bool $copy = false,
    ) {
    }

    public function target(): string
    {
        return (string) ($this->newPath ?? $this->oldPath);
    }

    public function isNewFile(): bool
    {
        return $this->oldPath === null;
    }

    public function isDeletion(): bool
    {
        return $this->newPath === null;
    }

    public function isRename(): bool
    {
        return $this->oldPath !== null && $this->newPath !== null && $this->oldPath !== $this->newPath;
    }

    /** @return string[] distinct paths this chunk reads or writes */
    public function paths(): array
    {
        return array_values(array_unique(array_filter([$this->oldPath, $this->newPath], static fn ($p) => $p !== null)));
    }

    public function withPaths(?string $old, ?string $new): self
    {
        return new self($old, $new, $this->extended, $this->hunks, $this->binary, $this->copy);
    }

    /** Git-style patch text for this file, with a/ b/ prefixes (applies with depth 1). */
    public function render(): string
    {
        $a = $this->oldPath ?? $this->newPath;
        $b = $this->newPath ?? $this->oldPath;
        $out = "diff --git a/{$a} b/{$b}\n";
        foreach ($this->extended as $line) {
            $out .= $line . "\n";
        }
        if ($this->isRename()) {
            $out .= "rename from {$this->oldPath}\nrename to {$this->newPath}\n";
        }
        if ($this->hunks !== []) {
            $out .= '--- ' . ($this->oldPath === null ? '/dev/null' : 'a/' . $this->oldPath) . "\n";
            $out .= '+++ ' . ($this->newPath === null ? '/dev/null' : 'b/' . $this->newPath) . "\n";
            foreach ($this->hunks as $hunk) {
                $out .= $hunk->render();
            }
        }

        return $out;
    }

    public function sha(): string
    {
        return hash('sha256', $this->render());
    }
}
