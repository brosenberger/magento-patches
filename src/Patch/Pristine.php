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

use BroCode\MagentoPatches\Magento\MagentoInfo;
use Composer\Composer;
use Composer\Util\ProcessExecutor;
use RuntimeException;

/**
 * Packages as `composer install` unpacks them, before composer-patches runs — downloaded once per
 * run into scratch (normally from Composer's cache).
 *
 * vendor/ alone cannot tell "the release already contains this change" from "an old script,
 * cweagans 1.x or a hand edit put it there". Only the first may be skipped: in the second case the
 * change is gone again after the next `composer install`.
 */
final class Pristine
{
    /** @var array<string, Stage> */
    private array $stages = [];

    public function __construct(
        private readonly Composer $composer,
        private readonly MagentoInfo $info,
        private readonly ProcessExecutor $process,
        private readonly string $workDir,
    ) {
    }

    /** Stage over the pristine files of a package (for Validator checks). */
    public function stage(string $packageName): Stage
    {
        if (isset($this->stages[$packageName])) {
            return $this->stages[$packageName];
        }
        $package = $this->info->package($packageName);
        if ($package === null) {
            throw new RuntimeException(sprintf('%s is not installed.', $packageName));
        }
        $package = clone $package; // the download manager sets the installation source on it
        $slug = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $packageName);
        $dir = $this->workDir . '/' . $slug;

        $manager = $this->composer->getDownloadManager();
        $loop = $this->composer->getLoop();
        try {
            $loop->wait([$manager->download($package, $dir)]);
            $loop->wait([$manager->prepare('install', $package, $dir)]);
            $loop->wait([$manager->install($package, $dir)]);
            $loop->wait([$manager->cleanup('install', $package, $dir)]);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Cannot download the pristine %s to compare with vendor/: %s', $packageName, $e->getMessage()), 0, $e);
        }

        return $this->stages[$packageName] = new Stage($packageName, $dir, $this->workDir . '/stage-' . $slug, $this->process);
    }
}
