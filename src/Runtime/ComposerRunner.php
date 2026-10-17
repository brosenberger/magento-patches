<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Runtime;

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
