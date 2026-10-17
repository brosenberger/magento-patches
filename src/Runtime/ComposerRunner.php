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

namespace BroCode\MagentoPatches\Runtime;

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use RuntimeException;

/**
 * Runs Composer sub-commands in a separate process (same PHP, same Composer binary), so each
 * step works on fresh state: patches-relock, reinstall, require, install.
 */
final class ComposerRunner
{
    public function __construct(
        private readonly IOInterface $io,
        private readonly string $cwd,
    ) {
    }

    /** @param string[] $args */
    public function run(array $args, bool $quiet = false): void
    {
        $binary = getenv('COMPOSER_BINARY') ?: ($_SERVER['argv'][0] ?? 'composer');
        $command = array_merge([PHP_BINARY, $binary], $args, ['--no-interaction']);
        if ($this->io->isDecorated()) {
            $command[] = '--ansi';
        }
        if ($quiet && !$this->io->isVerbose()) {
            $command[] = '--quiet';
        }
        $this->io->write(sprintf('  <comment>$ composer %s</comment>', implode(' ', $args)), true, IOInterface::VERBOSE);

        $process = new ProcessExecutor($this->io);
        $timeout = ProcessExecutor::getTimeout();
        ProcessExecutor::setTimeout(0); // reinstall/require may take longer than Composer's 300s default
        try {
            if (method_exists($process, 'executeTty')) {
                $code = $process->executeTty($command, $this->cwd);
            } else {
                $output = '';
                $code = $process->execute($command, $output, $this->cwd);
                $this->io->write($output . $process->getErrorOutput());
            }
        } finally {
            ProcessExecutor::setTimeout($timeout);
        }
        if ($code !== 0) {
            throw new RuntimeException(sprintf('composer %s failed (exit %d).', implode(' ', $args), $code));
        }
    }
}
