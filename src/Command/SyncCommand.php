<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Command;

use BroCode\MagentoPatches\Store\Label;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SyncCommand extends AbstractPatchCommand
{
    protected function configure(): void
    {
        $this->setName('patches:sync')
            ->setDescription('Bring patches.json in line with patches/downloads/: register new downloads, rebuild replaced ones (CI)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Change nothing; exit 1 if a download is unregistered, changed since registration, or patches.lock.json is out of date')
            ->addOption('qpt-path', null, InputOption::VALUE_REQUIRED, 'Quality Patches checkout, used to know the latest -p release of your line')
            ->addSafetyOptions()
            ->setHelp(<<<'HELP'
Every stored download has its sha256 in patches/downloads/manifest.json. This command compares:

  new file, bulletin not registered   → registered (like `patches:security <file>`)
  file changed / added / removed for a
  registered bulletin                  → re-validated and re-applied with the new download
                                         (e.g. someone committed Adobe's replaced zip)
  no manifest yet                      → hashes of the current files are recorded

  <info>composer install && composer patches:sync</info>       apply in the build
  <info>composer patches:sync --check</info>                   gate: fail if the repo and patches.json disagree
HELP);
    }

    protected function mutates(InputInterface $input): bool
    {
        return !$input->getOption('check');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $ctx = $this->context($input);
        $io->write(sprintf('<info>%s</info>', $ctx->info->describe()));
        $manifest = $this->manifest($ctx);
        $check = (bool) $input->getOption('check');

        $pending = [];
        $changed = [];
        $adopt = [];
        $ids = array_unique(array_merge(array_keys($ctx->downloads->all()), $manifest->ids()));
        foreach ($ids as $id) {
            if ($id === '') {
                foreach ($ctx->downloads->all()[''] ?? [] as $file) {
                    $io->writeError(sprintf('<warning>%s: cannot tell the bulletin — move it to %s/<ID>/</warning>', $ctx->info->relative($file), $ctx->info->relative($ctx->downloads->dir)));
                }
                continue;
            }
            $registered = $ctx->store->managed(Label::SECURITY, $id) !== [] || $ctx->store->isCovered($id);
            $state = $ctx->downloads->state($manifest, $id);
            if (!$registered) {
                if ($ctx->downloads->filesFor($id) !== []) {
                    $pending[$id] = $ctx->downloads->filesFor($id);
                }
                continue;
            }
            if (!$manifest->exists()) {
                $adopt[$id] = $state['new'];
                continue;
            }
            if ($state['changed'] !== [] || $state['new'] !== [] || $state['missing'] !== []) {
                $changed[$id] = $state;
            } else {
                $io->write(sprintf('  <info>✓</info> %s — %d file(s), hashes match', $id, count($state['ok'])), true, IOInterface::VERBOSE);
            }
        }
        $lockOk = $ctx->store->inSyncWith($ctx->lockFile);

        if ($check) {
            foreach (array_keys($pending) as $id) {
                $io->writeError(sprintf('<error>%s</error> stored in %s but not registered — run composer patches:sync', $id, $ctx->info->relative($ctx->downloads->dirFor($id))));
            }
            foreach ($changed as $id => $state) {
                $io->writeError(sprintf('<error>%s</error> download differs from what was registered (%s) — run composer patches:sync', $id, $this->describeState($state)));
            }
            if (!$lockOk) {
                $io->writeError(sprintf('<error>%s</error> does not match %s — run composer patches-relock', basename($ctx->lockFile), basename($ctx->store->path)));
            }
            if ($adopt !== []) {
                $io->write(sprintf('<comment>No %s yet — run composer patches:sync once to record the hashes.</comment>', $ctx->info->relative($manifest->path)));
            }
            $ok = $pending === [] && $changed === [] && $lockOk;
            if ($ok) {
                $io->write('<info>All stored downloads are registered, unchanged, and the lock is current.</info>');
            }

            return $ok ? 0 : 1;
        }

        $result = 0;
        if ($adopt !== []) {
            foreach ($adopt as $id => $rels) {
                foreach ($rels as $rel) {
                    $manifest->record($rel, $id, (string) file_get_contents($ctx->downloads->abs($rel)), null);
                }
            }
            file_put_contents($manifest->path, $manifest->encode());
            $io->write(sprintf('Recorded the hashes of %d stored download(s) in %s.', array_sum(array_map('count', $adopt)), $ctx->info->relative($manifest->path)));
        }
        foreach ($changed as $id => $state) {
            // The files on disk are the new truth; nothing to write or delete, only re-register.
            $result = max($result, $this->replaceSecurity($ctx, $input, $id, [], [], [], 'download changed since registration (' . $this->describeState($state) . ')', $manifest));
            $ctx = $this->context($input);
            $manifest = $this->manifest($ctx);
        }
        if ($pending !== []) {
            $io->write(sprintf('Registering %s …', implode(', ', array_keys($pending))));
            $result = max($result, $this->addSecurity($ctx, $input, $pending, false));
            $ctx = $this->context($input);
        }
        if ($pending === [] && $changed === []) {
            if (!$ctx->store->inSyncWith($ctx->lockFile)) {
                $io->write(sprintf('%s is out of date — relocking and re-applying …', basename($ctx->lockFile)));
                $ctx->runner->run(['patches-relock']);
                $ctx->runner->run(['patches-repatch']);
            } elseif ($adopt === []) {
                $io->write('<info>Nothing to do — every stored download is registered and unchanged.</info>');
            }
        }

        return $result;
    }

    /** @param array{ok: string[], changed: string[], new: string[], missing: string[]} $state */
    private function describeState(array $state): string
    {
        $parts = [];
        foreach (['changed', 'new', 'missing'] as $key) {
            if ($state[$key] !== []) {
                $parts[] = $key . ': ' . implode(', ', array_map('basename', $state[$key]));
            }
        }

        return implode('; ', $parts);
    }
}
