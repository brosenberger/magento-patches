<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Command;

use BroCode\MagentoPatches\Patch\DiffParser;
use BroCode\MagentoPatches\Patch\Stage;
use BroCode\MagentoPatches\Patch\Validator;
use BroCode\MagentoPatches\Runtime\LegacySetup;
use BroCode\MagentoPatches\Source\QualityPatches;
use BroCode\MagentoPatches\Store\Label;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class StatusCommand extends AbstractPatchCommand
{
    protected bool $mutates = false;

    protected function configure(): void
    {
        $this->setName('patches:status')
            ->setDescription('Show the installed Magento version and verify that every patch in patches.json is applied in vendor/')
            ->addOption('qpt-path', null, InputOption::VALUE_REQUIRED, 'Quality Patches checkout, used to know the latest -p release of your line')
            ->addOption('no-verify', null, InputOption::VALUE_NONE, 'Skip checking vendor/');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $ctx = $this->context($input);
        $info = $ctx->info;
        $problems = 0;

        $io->write(sprintf('<info>%s</info>%s', $info->describe(), $info->productPackage() ? ' via ' . $info->productPackage() : ''));
        try {
            $qpt = QualityPatches::locate($info, $input->getOption('qpt-path'));
            $latest = $qpt->latestRelease($info->version()->line);
            $io->write(sprintf('Quality Patches %s%s', $qpt->version(), $latest ? sprintf(', latest %s release: %s', $info->version()->line, $latest) : ''));
            if ($latest !== null && $latest->compareRank($info->version()) > 0) {
                $io->write(sprintf('<warning>Not on the latest %s release — isolated security patches are only tested on %s.</warning>', $info->version()->line, $latest));
            }
        } catch (Throwable) {
            $io->write('Quality Patches: not installed (optional)');
        }

        $legacy = LegacySetup::findings($ctx->composer->getPackage());
        if ($legacy !== []) {
            $io->write("\n<warning>Old patch mechanisms in composer.json</warning> (see the migration guide):");
            foreach ($legacy as $finding) {
                $problems++;
                $io->write('  <error>✗</error> ' . $finding);
            }
        }

        $entries = $ctx->store->entries();
        $io->write(sprintf("\n%s: %d entr%s", basename($ctx->store->path), count($entries), count($entries) === 1 ? 'y' : 'ies'));
        if (!$ctx->store->inSyncWith($ctx->lockFile)) {
            $problems++;
            $io->write(sprintf('<warning>%s is missing or out of date — run `composer patches-relock`.</warning>', basename($ctx->lockFile)));
        }

        $results = $input->getOption('no-verify') ? [] : $this->verify($ctx, $entries);
        $grouped = [];
        foreach ($entries as $i => $entry) {
            $parsed = Label::parse($entry['label']);
            $key = $parsed ? sprintf('[%s] %s', $parsed['tag'], $parsed['id']) : 'other: ' . $entry['label'];
            $grouped[$key][] = [$entry, $results[$i] ?? null];
        }
        foreach ($grouped as $key => $items) {
            $io->write("\n<comment>{$key}</comment>");
            foreach ($items as [$entry, $state]) {
                $mark = match ($state) {
                    'applied' => '<info>✓</info>',
                    'missing' => '<error>✗ not applied</error>',
                    'not-installed' => '<comment>– package not installed</comment>',
                    'unchecked' => '<comment>? not checked</comment>',
                    default => ' ',
                };
                if ($state === 'missing') {
                    $problems++;
                }
                $io->write(sprintf('  %s %s  %s', $mark, $entry['package'], $entry['url']));
            }
        }

        // Stored downloads vs. the sha256 recorded when they were registered.
        $manifest = new \BroCode\MagentoPatches\Store\DownloadManifest($ctx->downloads->manifestPath());
        $ids = array_values(array_filter(array_unique(array_merge(array_keys($ctx->downloads->all()), $manifest->ids())), static fn (string $id): bool => $id !== ''));
        if ($ids !== []) {
            $io->write(sprintf("\n<comment>Downloads</comment> (%s)", $info->relative($ctx->downloads->dir)));
            foreach ($ids as $id) {
                $state = $ctx->downloads->state($manifest, $id);
                foreach ($state['ok'] as $rel) {
                    $entry = (array) $manifest->file($rel);
                    $io->write(sprintf('  <info>✓</info> %s  sha256 %s%s', $rel, substr((string) $entry['sha256'], 0, 12), !empty($entry['source']) ? '  ← ' . $entry['source'] : ''));
                }
                foreach (['changed' => 'content differs from manifest', 'new' => 'not in manifest', 'missing' => 'missing'] as $key => $text) {
                    foreach ($state[$key] as $rel) {
                        $problems++;
                        $io->write(sprintf('  <error>✗ %s</error> %s — run composer patches:sync', $text, $rel));
                    }
                }
            }
        }

        return $problems === 0 ? 0 : 1;
    }

    /**
     * Reverse-applies each package's patches, last first, on a scratch copy of vendor/.
     * A patch counts as applied when it comes back out cleanly.
     *
     * @param list<array{package: string, label: string, url: string}> $entries
     * @return array<int, string> entry index => applied|missing|not-installed|unchecked
     */
    private function verify(\BroCode\MagentoPatches\Runtime\Context $ctx, array $entries): array
    {
        $results = [];
        $byPackage = [];
        foreach ($entries as $i => $entry) {
            $byPackage[$entry['package']][] = $i;
        }
        $workDir = $this->scratch('status');
        $validator = new Validator($ctx->process, $workDir . '/validate');
        foreach ($byPackage as $package => $indexes) {
            $installPath = $ctx->locator->installPathOf($package);
            if ($installPath === null) {
                foreach ($indexes as $i) {
                    $results[$i] = 'not-installed';
                }
                continue;
            }
            $stage = new Stage($package, $installPath, $workDir . '/stage/' . md5($package), $ctx->process);
            $reliable = true;
            foreach (array_reverse($indexes) as $i) {
                $file = $ctx->root . '/' . $entries[$i]['url'];
                if (!$reliable || !is_file($file)) {
                    // Remote URL or missing file: it cannot be taken out, so earlier ones can't be judged either.
                    $results[$i] = 'unchecked';
                    $reliable = false;
                    continue;
                }
                try {
                    $chunks = (new DiffParser())->parse((string) file_get_contents($file));
                } catch (Throwable) {
                    $results[$i] = 'unchecked';
                    continue;
                }
                $ok = true;
                foreach (array_reverse($chunks) as $chunk) {
                    $ok = $validator->revert($chunk, $stage) && $ok;
                }
                $results[$i] = $ok ? 'applied' : 'missing';
            }
        }

        return $results;
    }
}
