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

final class Hunk
{
    /**
     * @param string[] $lines raw hunk body lines including their line endings (" ", "-", "+", "\")
     */
    public function __construct(
        public readonly string $header,
        public readonly array $lines,
        public readonly int $oldStart,
        public readonly int $oldCount,
    ) {
    }

    /** Lines a hunk expects to find in the file: context + removed, without prefix and EOL. */
    public function oldSide(): array
    {
        return $this->collect([' ', '-']);
    }

    /** @return string[] */
    public function added(): array
    {
        return $this->collect(['+']);
    }

    /** @return string[] */
    public function removed(): array
    {
        return $this->collect(['-']);
    }

    /** @return string[] */
    private function collect(array $prefixes): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            if ($line !== '' && in_array($line[0], $prefixes, true)) {
                $out[] = rtrim(substr($line, 1), "\r\n");
            }
        }

        return $out;
    }

    public function render(): string
    {
        $text = $this->header . implode('', $this->lines);

        return str_ends_with($text, "\n") ? $text : $text . "\n";
    }
}
