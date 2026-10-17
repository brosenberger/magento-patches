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

namespace BroCode\MagentoPatches\Command;

use BroCode\MagentoPatches\Plan\Source;
use BroCode\MagentoPatches\Store\Label;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AddCommand extends AbstractPatchCommand
{
    protected function configure(): void
    {
        $this->setName('patches:add')
            ->setDescription('Add your own patch (project paths like vendor/magento/…, or package paths with --package), validated and split per package')
            ->addArgument('file', InputArgument::REQUIRED, 'Patch or diff file')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name for the entry (default: file name)')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Paths in the patch are relative to this package (e.g. a `git diff` made inside vendor/magento/module-x)')
            ->addSafetyOptions()
            ->setHelp(<<<'HELP'
Adds a custom patch the same way security and quality patches are added: split per package,
checked against what is installed (already applied / declared / ambiguous / lint), then written
to patches/ + patches.json, relocked and applied. Running it again changes nothing.

  <info>composer patches:add fixes/checkout-rounding.patch --name=checkout-rounding</info>
  <info>composer patches:add my.diff --package=magento/module-sales</info>
HELP);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        if (!is_file($file)) {
            throw new RuntimeException('File not found: ' . $file);
        }
        $name = (string) ($input->getOption('name') ?: pathinfo($file, PATHINFO_FILENAME));
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-');
        if ($name === '') {
            throw new RuntimeException('Give the patch a --name.');
        }

        $ctx = $this->context($input);
        $package = $input->getOption('package') ?: null;
        $source = Source::fromFile(Label::CUSTOM, $name, '', $file, $package);
        $plan = $ctx->planner->plan([$source]);

        return $this->executePlan($ctx, $input, $plan);
    }
}
