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

use BroCode\MagentoPatches\Patch\Chunk;
use BroCode\MagentoPatches\Patch\DiffParser;

/**
 * One patch to ingest — an isolated security patch file, a QPT patch, a custom patch,
 * or a stored package patch that is re-validated after an upgrade.
 */
final class Source
{
    /**
     * @param Chunk[] $chunks
     * @param ?string $package set when chunk paths are already relative to this package
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $id,
        public readonly string $detail,
        public readonly string $fileName,
        public readonly array $chunks,
        public readonly ?string $package = null,
        public readonly ?string $label = null,
    ) {
    }

    public static function fromFile(string $tag, string $id, string $detail, string $file, ?string $package = null): self
    {
        $chunks = (new DiffParser())->parse((string) file_get_contents($file));

        return new self($tag, $id, $detail, basename($file), $chunks, $package);
    }

    /** A patch already stored for one package, re-validated under its existing label. */
    public static function stored(string $tag, string $id, string $label, string $package, string $fileName, string $content): self
    {
        return new self($tag, $id, '', $fileName, (new DiffParser())->parse($content), $package, $label);
    }

    public function name(): string
    {
        return $this->id . ($this->fileName !== '' ? ' (' . $this->fileName . ')' : '');
    }
}
