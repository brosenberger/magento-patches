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

use BroCode\MagentoPatches\Magento\MagentoVersion;
use BroCode\MagentoPatches\Plan\Plan;
use BroCode\MagentoPatches\Plan\Source;
use BroCode\MagentoPatches\Source\IsolatedPatchFiles;
use BroCode\MagentoPatches\Source\QualityPatches;
use BroCode\MagentoPatches\Store\Label;
use BroCode\MagentoPatches\Store\Transaction;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class UpgradeCommand extends AbstractPatchCommand
{
    protected function configure(): void
    {
        $this->setName('patches:upgrade')
            ->setDescription('Upgrade Magento to a new release and carry security/quality patches over (drops what the release already contains)')
            ->addArgument('version', InputArgument::REQUIRED, 'Target version, e.g. 2.4.7-p11')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Product package to require (default: the installed magento/product-*-edition)')
            ->addOption('qpt-path', null, InputOption::VALUE_REQUIRED, 'Use a checkout of github.com/magento/quality-patches instead of the installed package')
            ->addOption('allow-deprecated', null, InputOption::VALUE_NONE, 'Keep QPT patches that are deprecated for the new version')
            ->addSafetyOptions(false)
            ->setHelp(<<<'HELP'
1. takes all [SEC] and [QPT] patches out (vendor/ clean except your own patches)
2. composer require <product>:<version> --update-with-all-dependencies
3. re-validates every [SEC] patch against the new code: already contained → dropped, still needed → kept
4. re-resolves [QPT] ids for the new versions
5. relock + reinstall + verify

Any failure — the require, a conflict, a failing patch — restores composer.json/lock,
patches.json/lock, the patch files and vendor/ to where they were.
HELP);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $ctx = $this->context($input);
        $target = MagentoVersion::parse((string) $input->getArgument('version'));
        if ($target === null || $target->isDateBuild()) {
            throw new RuntimeException('Give a Composer release version like 2.4.7-p11.');
        }
        $current = $ctx->info->version();
        if ($target->compareRank($current) <= 0) {
            throw new RuntimeException(sprintf('Installed is %s — %s is not newer.', $current, $target));
        }
        $product = (string) ($input->getOption('package') ?: $ctx->info->productPackage());
        if ($product === '') {
            throw new RuntimeException('No magento/product-*-edition package installed — pass --package=<name>.');
        }

        $parked = array_merge($ctx->store->managed(Label::SECURITY), $ctx->store->managed(Label::QUALITY));
        $contents = [];
        foreach ($parked as $i => $entry) {
            $contents[$i] = (string) @file_get_contents($ctx->root . '/' . $entry['url']);
        }
        $qptIds = $ctx->store->ids(Label::QUALITY);
        if ($qptIds !== []) {
            QualityPatches::locate($ctx->info, $input->getOption('qpt-path')); // fail early if missing
        }

        $io->write(sprintf('<info>%s</info> → %s %s', $ctx->info->describe(), $product, $target));
        $tx = new Transaction();
        foreach ($ctx->composerFiles() as $file) {
            $tx->track($file);
        }
        $packages = array_column($parked, 'package');
        try {
            if ($parked !== []) {
                $io->write(sprintf('Taking %d security/quality patch entr%s out …', count($parked), count($parked) === 1 ? 'y' : 'ies'));
                $ctx->applier->apply($tx, [], $parked);
            }

            $io->write(sprintf('Upgrading to %s:%s …', $product, $target));
            $ctx->runner->run(['require', $product . ':' . $target, '--update-with-all-dependencies']);

            $new = $this->context($input, true);
            $io->write(sprintf('Now <info>%s</info>. Re-validating patches …', $new->info->describe()));

            $plan = new Plan();
            $sources = [];
            $collector = new IsolatedPatchFiles($this->scratch('downloads'));
            $latest = $this->latestRelease($new, $input);
            $securityIds = [];
            foreach ($parked as $entry) {
                $label = Label::parse($entry['label']);
                if ($label['tag'] === Label::SECURITY) {
                    $securityIds[$label['id']] = true;
                }
            }
            foreach (array_keys($securityIds) as $id) {
                // Prefer the stored download: it may contain the file made for the new version.
                $stored = $new->downloads->filesFor((string) $id);
                if ($stored !== []) {
                    try {
                        $selection = $collector->select($collector->collect($stored), $new->info, $latest);
                        foreach ($selection['files'] as $file) {
                            $sources[] = Source::fromFile(Label::SECURITY, (string) $id, basename($file), $file);
                        }
                        $new->store->unmarkBackport((string) $id); // the release now has its own file
                        $plan->note('info', sprintf('%s: re-selected from the stored download for %s', $id, $target));
                        continue;
                    } catch (RuntimeException) {
                        // no file for the new version — re-validate what was registered (a backport stays one)
                    }
                }
                foreach ($parked as $i => $entry) {
                    if (Label::matches($entry['label'], Label::SECURITY, (string) $id)) {
                        $sources[] = Source::stored(Label::SECURITY, (string) $id, $entry['label'], $entry['package'], basename($entry['url']), $contents[$i]);
                    }
                }
            }
            if ($qptIds !== []) {
                $qpt = QualityPatches::locate($new->info, $input->getOption('qpt-path'));
                foreach ($qpt->resolve($qptIds, $new->info, $plan, (bool) $input->getOption('allow-deprecated')) as $item) {
                    $sources[] = Source::fromFile(Label::QUALITY, $item['id'], $item['title'], $item['file']);
                }
            }
            $plan = $new->planner->plan($sources, $plan);
            $this->report($plan);
            if ($plan->hasConflicts()) {
                throw new RuntimeException('Some patches conflict with the new version — resolve them first (see above).');
            }
            $this->summarize($plan);
            // Bulletins whose fixes are all in the new release: keep them visible as covered.
            foreach (array_keys($securityIds) as $id) {
                $planned = array_filter($plan->patches, static fn ($p): bool => Label::matches($p->label, Label::SECURITY, (string) $id));
                if ($planned === []) {
                    $new->store->markCovered((string) $id, sprintf('contained in %s (%s)', $target, date('Y-m-d')));
                }
            }
            $packages = array_merge($packages, $plan->packages());
            $new->applier->apply($tx, $plan->patches, [], $plan->stages);
            $tx->write($new->store->path, $new->store->encode());
            $tx->commit();
        } catch (Throwable $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            $io->writeError('Rolling back the upgrade …');
            $tx->rollback();
            $ctx->applier->restore(array_values(array_unique($packages)), true);

            return 1;
        }

        $kept = count($plan->patches);
        $io->write(sprintf(
            '<info>Upgraded to %s.</info> %d patch file(s) kept; everything else is contained in the release or no longer applies. Commit composer.json, composer.lock, patches.json, patches.lock.json and patches/.',
            $target,
            $kept
        ));

        return 0;
    }
}
