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
use BroCode\MagentoPatches\Runtime\Context;
use BroCode\MagentoPatches\Runtime\LegacySetup;
use BroCode\MagentoPatches\Source\IsolatedPatchFiles;
use BroCode\MagentoPatches\Source\QualityPatches;
use BroCode\MagentoPatches\Store\DownloadManifest;
use BroCode\MagentoPatches\Store\Label;
use BroCode\MagentoPatches\Store\Transaction;
use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Util\Filesystem;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class AbstractPatchCommand extends BaseCommand
{
    /** Commands that change patches.json / vendor are guarded and locked. */
    protected bool $mutates = true;

    /** @var resource|null */
    private $lockHandle;
    private ?string $workDir = null;

    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    protected function mutates(InputInterface $input): bool
    {
        return $this->mutates;
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // A dry run only reads vendor/ and works in a scratch copy — no lock needed.
            $dryRun = $input->hasOption('dry-run') && $input->getOption('dry-run');
            if ($this->mutates($input) && !$dryRun) {
                $this->lock();
            }
            if ($this->mutates($input)) {
                foreach (LegacySetup::findings($this->composer()->getPackage()) as $finding) {
                    $this->getIO()->writeError('<warning>' . $finding . '</warning>');
                }
            }

            return $this->handle($input, $output);
        } catch (RuntimeException $e) {
            $this->getIO()->writeError('<error>' . $e->getMessage() . '</error>');

            return 1;
        } finally {
            $this->cleanup();
        }
    }

    protected function addSafetyOptions(bool $dryRun = true): static
    {
        if ($dryRun) {
            $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show the result, change nothing');
        }

        return $this
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail instead of skipping a change whose declarations already exist elsewhere in the file')
            ->addOption('skip-unsupported', null, InputOption::VALUE_NONE, 'Leave out changes to files no installed package provides, instead of failing');
    }

    /**
     * BaseCommand::requireComposer() only exists since Composer 2.3; Composer 2.2 LTS
     * (still shipped in some CI images) has getComposer(true) with the same contract.
     */
    protected function composer(): Composer
    {
        return method_exists($this, 'requireComposer') ? $this->requireComposer() : $this->getComposer(true);
    }

    /** Scratch directory for this run, removed when the command ends. */
    protected function scratch(string $name): string
    {
        $this->workDir ??= sys_get_temp_dir() . '/brocode-magento-patches/' . getmypid() . '-' . bin2hex(random_bytes(3));

        return $this->workDir . '/' . $name;
    }

    protected function context(InputInterface $input, bool $fresh = false): Context
    {
        if ($fresh) {
            $this->resetComposer();
        }

        return new Context(
            $this->composer(),
            $this->getIO(),
            $this->scratch('ctx-' . bin2hex(random_bytes(3))),
            $input->hasOption('strict') && (bool) $input->getOption('strict'),
            $input->hasOption('skip-unsupported') && (bool) $input->getOption('skip-unsupported'),
        );
    }



    /** One run per project at a time. */
    protected function lock(): void
    {
        $file = sys_get_temp_dir() . '/brocode-magento-patches-' . md5((string) getcwd()) . '.lock';
        $handle = fopen($file, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another patch command is running for this project.');
        }
        $this->lockHandle = $handle;
    }

    protected function report(Plan $plan): void
    {
        $io = $this->getIO();
        $styles = ['info' => 'info', 'skip' => 'comment', 'warn' => 'warning'];
        $labels = ['info' => '   ok', 'skip' => ' skip', 'warn' => ' WARN'];
        foreach ($plan->messages as [$level, $text]) {
            $io->write(sprintf('<%1$s>%2$s</%1$s> %3$s', $styles[$level], $labels[$level], $text));
        }
        foreach ($plan->conflicts as $conflict) {
            $io->writeError(sprintf('<error>CONFLICT</error> %s', $conflict));
        }
    }

    protected function summarize(Plan $plan): void
    {
        foreach ($plan->patches as $patch) {
            $this->getIO()->write(sprintf(
                '  <info>+</info> %s <comment>%s</comment> (%d file%s) → %s',
                $patch->package,
                $patch->label,
                count($patch->files),
                count($patch->files) === 1 ? '' : 's',
                $patch->path
            ));
        }
    }

    protected function latestRelease(Context $ctx, InputInterface $input): ?MagentoVersion
    {
        try {
            $path = $input->hasOption('qpt-path') ? $input->getOption('qpt-path') : null;

            return QualityPatches::locate($ctx->info, $path)->latestRelease($ctx->info->version()->line);
        } catch (Throwable) {
            return null; // QPT is optional here
        }
    }

    protected function manifest(Context $ctx): DownloadManifest
    {
        return new DownloadManifest($ctx->downloads->manifestPath());
    }

    /**
     * Isolated security patches / hotfixes, given as downloads per bulletin id: picks the files for
     * the installed version, validates and registers them, and (when $store) keeps the downloads
     * in patches/downloads/<id>/ with their sha256 in the manifest — all in one transaction.
     *
     * @param array<string, string[]> $byId bulletin id => zip / patch files / folders
     * @param array<string, string> $origins input file => URL it was downloaded from
     */
    protected function addSecurity(Context $ctx, InputInterface $input, array $byId, bool $store, array $origins = [], ?DownloadManifest $manifest = null, ?MagentoVersion $as = null): int
    {
        $io = $this->getIO();
        $manifest ??= $this->manifest($ctx);
        $collector = new IsolatedPatchFiles($this->scratch('downloads'));
        $latest = $this->latestRelease($ctx, $input);
        $plan = new Plan();
        $patchSources = [];
        $writes = [];
        foreach ($byId as $id => $inputs) {
            $from = $as ?? $this->backportVersion($ctx, $id);
            try {
                $selection = $collector->select($collector->collect($inputs), $ctx->info, $latest, $from);
            } catch (RuntimeException $e) {
                $plan->conflicts[] = $id . ': ' . $e->getMessage();
                continue;
            }
            foreach ($selection['notes'] as $note) {
                $plan->note('warn', $id . ': ' . $note);
            }
            foreach ($selection['files'] as $file) {
                $io->write(sprintf('  %s: using <comment>%s</comment>', $id, basename($file)));
                $patchSources[] = Source::fromFile(Label::SECURITY, $id, $this->sourceDetail($ctx, basename($file), $from), $file);
            }
            if ($from !== null && $from->raw !== $ctx->info->version()->raw) {
                $ctx->store->markBackport($id, $from->raw);
            }
            if ($store) {
                $byHash = [];
                foreach ($inputs as $in) {
                    if (is_file($in)) {
                        $byHash[hash_file('sha256', $in)] = $origins[$in] ?? null;
                    }
                }
                foreach ($ctx->downloads->planStore($id, $inputs) as $path => $content) {
                    $writes[$path] = $content;
                    $manifest->record($ctx->downloads->rel($path), $id, $content, $byHash[hash('sha256', $content)] ?? null);
                }
            }
            foreach ($ctx->downloads->filesFor($id) as $file) {
                $rel = $ctx->downloads->rel($file);
                if ($manifest->file($rel) === null) {
                    $manifest->record($rel, $id, (string) file_get_contents($file), null); // committed by hand
                }
            }
        }
        $plan = $ctx->planner->plan($patchSources, $plan);

        // Bulletins that end up without any entry are recorded, so the check stops listing them.
        $covered = false;
        if (!$plan->hasConflicts()) {
            foreach (array_keys($byId) as $id) {
                $planned = array_filter($plan->patches, static fn ($p): bool => Label::matches($p->label, Label::SECURITY, $id));
                if ($planned === [] && $ctx->store->managed(Label::SECURITY, $id) === [] && !$ctx->store->isCovered($id)) {
                    $ctx->store->markCovered($id, sprintf('nothing to add on %s (%s)', $ctx->info->version(), date('Y-m-d')));
                    $plan->note('info', $id . ': already contained in the installed code — recorded as covered');
                    $covered = true;
                }
            }
        }
        foreach (array_keys($writes) as $path) {
            $plan->note('info', 'keeps download ' . $ctx->info->relative($path));
        }
        $encoded = $manifest->encode();
        if (!is_file($manifest->path) || file_get_contents($manifest->path) !== $encoded) {
            $writes[$manifest->path] = $encoded;
        }

        return $this->executePlan($ctx, $input, $plan, [], $writes, [], $covered);
    }

    /**
     * A bulletin's download changed (replaced on Adobe's server, or in the repo): bring the stored
     * files, the manifest and the registered patches in line with the new download.
     *
     * The new files are compared hunk by hunk with what is registered. Same content (only
     * repackaged) → files + manifest only. Different → every managed patch of the affected
     * packages is taken out, then re-validated in its original order with the new download in
     * place of the old one, and applied again. All-or-nothing, like every other change.
     *
     * @param array<string, string> $writes absolute download path => new content
     * @param string[] $deletes absolute download paths that are obsolete
     * @param array<string, ?string> $origins absolute download path => source URL (for the manifest)
     */
    protected function replaceSecurity(Context $ctx, InputInterface $input, string $id, array $writes, array $deletes, array $origins, string $reason, ?DownloadManifest $manifest = null, ?MagentoVersion $as = null): int
    {
        $io = $this->getIO();
        $from = $as ?? $this->backportVersion($ctx, $id);
        $manifest ??= $this->manifest($ctx);
        $io->write(sprintf('<comment>%s</comment>: %s', $id, $reason));

        // The download set as it will be: kept files + new contents (staged in scratch for reading).
        $staged = $this->scratch('replace-' . $id);
        (new Filesystem())->ensureDirectoryExists($staged);
        $set = [];
        foreach ($ctx->downloads->filesFor($id) as $file) {
            if (!in_array($file, $deletes, true) && !isset($writes[$file])) {
                $set[] = $file;
            }
        }
        foreach ($writes as $path => $content) {
            $copy = $staged . '/' . basename($path);
            file_put_contents($copy, $content);
            $set[] = $copy;
        }
        foreach ($writes as $path => $content) {
            $manifest->record($ctx->downloads->rel($path), $id, $content, $origins[$path] ?? null);
        }
        foreach ($deletes as $path) {
            $manifest->forget($ctx->downloads->rel($path));
        }
        foreach (array_keys($manifest->filesFor($id)) as $rel) {
            if (!is_file($ctx->downloads->abs($rel)) && !isset($writes[$ctx->downloads->abs($rel)])) {
                $manifest->forget($rel); // removed from the repo
            }
        }
        foreach ($ctx->downloads->filesFor($id) as $file) {
            if (!in_array($file, $deletes, true) && !isset($writes[$file])) {
                $manifest->record($ctx->downloads->rel($file), $id, (string) file_get_contents($file), $origins[$file] ?? null);
            }
        }
        $fileWrites = $writes + [$manifest->path => $manifest->encode()];

        $collector = new IsolatedPatchFiles($this->scratch('replace-collect-' . $id));
        try {
            $selection = $collector->select($collector->collect($set), $ctx->info, $this->latestRelease($ctx, $input), $from);
        } catch (RuntimeException $e) {
            $plan = new Plan();
            $plan->conflicts[] = $id . ': ' . $e->getMessage();

            return $this->executePlan($ctx, $input, $plan);
        }
        $registered = $ctx->store->managed(Label::SECURITY, $id);
        $old = [];
        foreach ($registered as $entry) {
            $old[] = (string) @file_get_contents($ctx->root . '/' . $entry['url']);
        }
        if ($registered !== [] && $this->hunkPrint($ctx, $old, null) === $this->hunkPrint($ctx, array_map('file_get_contents', $selection['files']), $ctx)) {
            $plan = new Plan();
            $plan->note('info', $id . ': patch content unchanged (repackaged) — updating the stored download only');

            return $this->executePlan($ctx, $input, $plan, [], $fileWrites, $deletes, true);
        }
        if ($input->hasOption('dry-run') && $input->getOption('dry-run')) {
            $io->write(sprintf('  %s: patch content changed — would re-validate and re-apply. <comment>Dry run — nothing was changed.</comment>', $id));

            return 0;
        }

        // Packages that carry this bulletin; all managed entries there are rebuilt in order.
        $packages = array_values(array_unique(array_column($registered, 'package')));
        $affected = array_values(array_filter($ctx->store->entries(), static fn (array $e): bool => in_array($e['package'], $packages, true)));
        foreach ($affected as $entry) {
            if (Label::parse($entry['label']) === null) {
                throw new RuntimeException(sprintf('%s also has a patch not managed by this plugin ("%s") — replace %s by hand: patches:remove %s, then patches:security <new file>.', $entry['package'], $entry['label'], $id, $id));
            }
        }
        $contents = [];
        foreach ($affected as $i => $entry) {
            $contents[$i] = (string) @file_get_contents($ctx->root . '/' . $entry['url']);
        }

        $tx = new Transaction();
        $touched = $packages;
        try {
            foreach ($fileWrites as $path => $content) {
                $tx->write($path, $content);
            }
            foreach ($deletes as $path) {
                $tx->delete($path);
            }
            if ($affected !== []) {
                $io->write(sprintf('Taking the patches of %s out to rebuild them …', implode(', ', $packages)));
                $ctx->applier->apply($tx, [], $affected);
            }
            $new = $this->context($input);
            $sources = [];
            $inserted = false;
            $newSources = array_map(fn (string $f): Source => Source::fromFile(Label::SECURITY, $id, $this->sourceDetail($new, basename($f), $from), $f), $selection['files']);
            if ($from !== null && $from->raw !== $new->info->version()->raw) {
                $new->store->markBackport($id, $from->raw);
            }
            foreach ($affected as $i => $entry) {
                $label = Label::parse($entry['label']);
                if ($label['tag'] === Label::SECURITY && strcasecmp($label['id'], $id) === 0) {
                    if (!$inserted) {
                        array_push($sources, ...$newSources);
                        $inserted = true;
                    }
                    continue;
                }
                $sources[] = Source::stored($label['tag'], $label['id'], $entry['label'], $entry['package'], basename($entry['url']), $contents[$i]);
            }
            if (!$inserted) {
                array_push($sources, ...$newSources);
            }
            $plan = $new->planner->plan($sources);
            $this->report($plan);
            if ($plan->hasConflicts()) {
                throw new RuntimeException(sprintf('The new download of %s does not fit — nothing was changed.', $id));
            }
            $this->summarize($plan);
            $new->store->unmarkCovered($id);
            if (array_filter($plan->patches, static fn ($p): bool => Label::matches($p->label, Label::SECURITY, $id)) === []) {
                $new->store->markCovered($id, sprintf('nothing to add on %s (%s)', $new->info->version(), date('Y-m-d')));
            }
            $touched = array_values(array_unique(array_merge($touched, $plan->packages())));
            $new->applier->apply($tx, $plan->patches, [], $plan->stages);
            $tx->write($new->store->path, $new->store->encode());
            $tx->commit();
        } catch (Throwable $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            $io->writeError('Rolling back …');
            $tx->rollback();
            $ctx->applier->restore($touched);

            return 1;
        }
        $io->write(sprintf('<info>%s updated to the new download.</info> Commit patches/, %s and %s.', $id, basename($ctx->store->path), basename($ctx->lockFile)));

        return 0;
    }

    /** Version a bulletin was registered from with --from-version, if any. */
    protected function backportVersion(Context $ctx, string $id): ?MagentoVersion
    {
        $raw = $ctx->store->backportFrom($id);

        return $raw !== null ? MagentoVersion::parse($raw) : null;
    }

    /** Label detail: the file name, marked when the file was made for another version. */
    protected function sourceDetail(Context $ctx, string $fileName, ?MagentoVersion $from): string
    {
        return $from !== null && $from->raw !== $ctx->info->version()->raw ? $fileName . ' [backport from ' . $from->raw . ']' : $fileName;
    }

    /**
     * Fingerprint of the hunks a set of patches applies, independent of packaging, headers and
     * index lines. Stored package patches are package-relative ($locate = null); downloads are
     * project-relative and mapped to their packages first.
     *
     * @param string[] $patches patch texts
     */
    private function hunkPrint(Context $ctx, array $patches, ?Context $locate): string
    {
        $keys = [];
        $parser = new \BroCode\MagentoPatches\Patch\DiffParser();
        foreach ($patches as $text) {
            try {
                $chunks = $parser->parse((string) $text);
            } catch (Throwable) {
                return 'unparsable-' . hash('sha256', (string) $text);
            }
            foreach ($chunks as $chunk) {
                $target = $chunk->target();
                if ($locate !== null) {
                    $location = $locate->locator->locate($target);
                    if (!$location->isPatchable()) {
                        continue; // not installed / unsupported: never registered
                    }
                    $target = $location->package . ':' . $location->relPath;
                } else {
                    $target = '?:' . $target;
                }
                $body = '';
                foreach ($chunk->hunks as $hunk) {
                    $body .= preg_replace('/^(@@ [^@]+ @@).*$/m', '$1', $hunk->render());
                }
                $keys[] = preg_replace('/^[^:]*:/', '', $target) . "\n" . $body;
            }
        }
        sort($keys);

        return hash('sha256', implode("\0", $keys));
    }

    /**
     * Validate → write → relock → reinstall → verify, all-or-nothing.
     *
     * @param list<array{package: string, label: string, url: string}> $remove
     * @param array<string, string> $writes extra files to write in the same transaction (downloads)
     * @param string[] $deletes extra files to delete in the same transaction
     */
    protected function executePlan(
        Context $ctx,
        InputInterface $input,
        Plan $plan,
        array $remove = [],
        array $writes = [],
        array $deletes = [],
        bool $storeChanged = false,
    ): int {
        $io = $this->getIO();
        $this->report($plan);
        if ($plan->hasConflicts()) {
            $io->writeError('<error>Nothing was changed.</error>');

            return 1;
        }
        $filesOnly = $plan->isEmpty() && $remove === [];
        if ($filesOnly && $writes === [] && $deletes === [] && !$storeChanged) {
            $io->write('<info>Nothing to do — everything is already in place.</info>');

            return 0;
        }
        $this->summarize($plan);
        foreach ($remove as $entry) {
            $io->write(sprintf('  <comment>-</comment> %s <comment>%s</comment>', $entry['package'], $entry['label']));
        }
        foreach ($deletes as $path) {
            $io->write(sprintf('  <comment>-</comment> %s', $ctx->info->relative($path)));
        }
        if ($input->hasOption('dry-run') && $input->getOption('dry-run')) {
            $io->write('<comment>Dry run — nothing was changed.</comment>');

            return 0;
        }

        $tx = new Transaction();
        $packages = array_values(array_unique(array_merge($plan->packages(), array_column($remove, 'package'))));
        try {
            foreach ($writes as $path => $content) {
                $tx->write($path, $content);
            }
            foreach ($deletes as $path) {
                $tx->delete($path);
            }
            if ($filesOnly) {
                $tx->write($ctx->store->path, $ctx->store->encode()); // no vendor change needed
            } else {
                $ctx->applier->apply($tx, $plan->patches, $remove, $plan->stages);
            }
            $tx->commit();
        } catch (Throwable $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            $io->writeError('Rolling back …');
            $tx->rollback();
            if (!$filesOnly) {
                $ctx->applier->restore($packages);
            }

            return 1;
        }
        $io->write(sprintf(
            '<info>Done.</info> Commit %s, %s and the patches/ folder; deploys apply them with plain `composer install`.',
            basename($ctx->store->path),
            basename($ctx->lockFile)
        ));

        return 0;
    }

    private function cleanup(): void
    {
        if ($this->workDir !== null && is_dir($this->workDir)) {
            (new Filesystem())->removeDirectory($this->workDir);
        }
        $this->workDir = null;
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }
}
