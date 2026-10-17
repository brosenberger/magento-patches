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
use BroCode\MagentoPatches\Plan\Source;
use BroCode\MagentoPatches\Runtime\Context;
use BroCode\MagentoPatches\Source\QualityPatches;
use BroCode\MagentoPatches\Store\Label;
use BroCode\MagentoPatches\Store\Transaction;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class QualityCommand extends AbstractPatchCommand
{
    protected function configure(): void
    {
        $this->setName('patches:quality')
            ->setDescription('Add Adobe Quality Patches (QPT ids) — the file matching the installed versions is picked automatically')
            ->addArgument('ids', InputArgument::IS_ARRAY, 'QPT patch ids, e.g. ACSD-56789 MDVA-12345')
            ->addOption('resync', null, InputOption::VALUE_NONE, 'Re-resolve all registered QPT patches against the installed versions (after an upgrade or QPT update)')
            ->addOption('qpt-path', null, InputOption::VALUE_REQUIRED, 'Use a checkout of github.com/magento/quality-patches instead of the installed package')
            ->addOption('allow-deprecated', null, InputOption::VALUE_NONE, 'Also add patches QPT marks as deprecated for your version')
            ->addSafetyOptions()
            ->setHelp(<<<'HELP'
Resolves QPT ids against the versions Composer has installed (magento2-base, ee-base, b2b-base, …),
follows "require" and "replaced-with", and adds the result like any other patch: per package,
validated, idempotent.

  <info>composer patches:quality ACSD-56789</info>
  <info>composer patches:quality</info>            list registered QPT patches
  <info>composer patches:quality --resync</info>   after `composer update magento/quality-patches`
HELP);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $ctx = $this->context($input);
        $qpt = QualityPatches::locate($ctx->info, $input->getOption('qpt-path'));
        $registered = $ctx->store->ids(Label::QUALITY);
        $requested = array_map('strtoupper', (array) $input->getArgument('ids'));
        $io->write(sprintf('<info>%s</info>, Quality Patches %s', $ctx->info->describe(), $qpt->version()));

        if (!$input->getOption('resync')) {
            if ($requested === []) {
                $io->write($registered === [] ? 'No QPT patches registered.' : 'Registered: ' . implode(', ', $registered));

                return 0;
            }
            $plan = new Plan();
            $new = [];
            foreach ($requested as $id) {
                if (in_array($id, array_map('strtoupper', $registered), true)) {
                    $plan->note('skip', $id . ': already registered');
                } else {
                    $new[] = $id;
                }
            }
            $plan = $ctx->planner->plan($this->sources($qpt, $ctx, $new, $plan, (bool) $input->getOption('allow-deprecated')), $plan);

            return $this->executePlan($ctx, $input, $plan);
        }

        return $this->resync($ctx, $qpt, $input, array_values(array_unique(array_merge($registered, $requested))));
    }

    /** Remove all QPT entries (vendor back without them), then resolve and add them again for the installed versions. */
    private function resync(Context $ctx, QualityPatches $qpt, InputInterface $input, array $ids): int
    {
        if ($input->getOption('dry-run')) {
            throw new RuntimeException('--resync cannot be a dry run: it has to take the current QPT patches out of vendor/ first.');
        }
        $old = $ctx->store->managed(Label::QUALITY);
        $tx = new Transaction();
        $packages = array_column($old, 'package');
        try {
            if ($old !== []) {
                $this->getIO()->write('Taking registered QPT patches out …');
                $ctx->applier->apply($tx, [], $old);
            }
            $plan = new Plan();
            $plan = $ctx->planner->plan($this->sources($qpt, $ctx, $ids, $plan, (bool) $input->getOption('allow-deprecated')), $plan);
            $this->report($plan);
            if ($plan->hasConflicts()) {
                throw new RuntimeException('Conflicts while re-resolving QPT patches.');
            }
            $this->summarize($plan);
            $packages = array_merge($packages, $plan->packages());
            $ctx->applier->apply($tx, $plan->patches, [], $plan->stages);
            $tx->commit();
        } catch (Throwable $e) {
            $this->getIO()->writeError('<error>' . $e->getMessage() . '</error>');
            $this->getIO()->writeError('Rolling back …');
            $tx->rollback();
            $ctx->applier->restore(array_values(array_unique($packages)));

            return 1;
        }
        $this->getIO()->write('<info>QPT patches re-synced.</info>');

        return 0;
    }

    /** @return Source[] */
    private function sources(QualityPatches $qpt, Context $ctx, array $ids, Plan $plan, bool $allowDeprecated): array
    {
        $sources = [];
        foreach ($qpt->resolve($ids, $ctx->info, $plan, $allowDeprecated) as $item) {
            $sources[] = Source::fromFile(Label::QUALITY, $item['id'], $item['title'], $item['file']);
        }

        return $sources;
    }
}
