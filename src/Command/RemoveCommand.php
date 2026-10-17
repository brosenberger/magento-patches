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

use BroCode\MagentoPatches\Plan\Plan;
use BroCode\MagentoPatches\Source\Bulletin;
use BroCode\MagentoPatches\Store\Label;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RemoveCommand extends AbstractPatchCommand
{
    protected function configure(): void
    {
        $this->setName('patches:remove')
            ->setDescription('Remove managed patches by id (APSB26-153, ACSD-12345, your custom name); for bulletins also the stored download')
            ->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Ids to remove')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Only entries of this kind: SEC, QPT or CUSTOM')
            ->addOption('keep-download', null, InputOption::VALUE_NONE, 'Keep patches/downloads/<ID>/ (note: patches:sync would register it again)')
            ->addSafetyOptions();
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->context($input);
        $tag = $input->getOption('tag') ? strtoupper((string) $input->getOption('tag')) : null;
        if ($tag !== null && !in_array($tag, Label::TAGS, true)) {
            throw new RuntimeException('--tag must be one of ' . implode(', ', Label::TAGS));
        }
        $remove = [];
        $deletes = [];
        $storeChanged = false;
        foreach ((array) $input->getArgument('ids') as $id) {
            $found = [];
            foreach ($tag !== null ? [$tag] : Label::TAGS as $t) {
                array_push($found, ...$ctx->store->managed($t, (string) $id));
            }
            $other = false;
            if ($tag === null || $tag === Label::SECURITY) {
                $bulletin = Bulletin::normalizeId((string) $id);
                if ($ctx->store->isCovered($bulletin)) {
                    $ctx->store->unmarkCovered($bulletin);
                    $storeChanged = $other = true;
                }
                if (!$input->getOption('keep-download')) {
                    foreach ($ctx->downloads->filesFor($bulletin) as $file) {
                        $deletes[] = $file;
                        $other = true;
                    }
                }
            }
            if ($found === [] && !$other) {
                throw new RuntimeException(sprintf('No managed patch, covered bulletin or download with id %s.', $id));
            }
            array_push($remove, ...$found);
        }

        $writes = [];
        if ($deletes !== []) {
            $manifest = $this->manifest($ctx);
            foreach ($deletes as $file) {
                $manifest->forget($ctx->downloads->rel($file));
            }
            $writes[$manifest->path] = $manifest->encode();
        }

        return $this->executePlan($ctx, $input, new Plan(), $remove, $writes, $deletes, $storeChanged);
    }
}
