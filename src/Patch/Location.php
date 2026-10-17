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

final class Location
{
    public const PACKAGE = 'package';
    public const MAPPED = 'mapped';           // root file deployed from a package (magento2-base "map")
    public const NOT_INSTALLED = 'not-installed';
    public const UNSUPPORTED = 'unsupported';
    public const IGNORED = 'ignored';         // known to be no part of the fix (Adobe's vendor/bin/patch-status)

    public function __construct(
        public readonly string $status,
        public readonly ?string $package = null,
        public readonly ?string $installPath = null,
        public readonly ?string $relPath = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function isPatchable(): bool
    {
        return $this->status === self::PACKAGE || $this->status === self::MAPPED;
    }
}
