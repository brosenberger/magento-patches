<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Patch;

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use RuntimeException;

/**
 * Scratch copy of the files of one package that a run touches. Every validated chunk is
 * really applied here, so later chunks/patches of the same run are checked against the
 * state they will actually meet — and the final file contents can be compared with vendor/
 * after composer-patches did its job.
 */
final class Stage
{
    /** @var array<string, ?string> relPath => content in vendor before this run (null = did not exist) */
    private array $before = [];

    public function __construct(
        public readonly string $package,
        public readonly string $installPath,
        public readonly string $dir,
        ProcessExecutor $process,
    ) {
        (new Filesystem())->ensureDirectoryExists($dir);
        // Same setup as composer-patches' GitInitPatcher: a throw-away repo around the files.
        $out = '';
        if ($process->execute(['git', '-C', $dir, 'init', '-q'], $out) !== 0) {
            throw new RuntimeException('git init failed in ' . $dir . ': ' . $process->getErrorOutput());
        }
    }

    /** Make sure the given package-relative files are present in the stage (copied once from vendor). */
    public function ensure(string ...$relPaths): void
    {
        foreach ($relPaths as $rel) {
            if (array_key_exists($rel, $this->before)) {
                continue;
            }
            $source = $this->installPath . '/' . $rel;
            if (is_file($source)) {
                $content = (string) file_get_contents($source);
                $this->before[$rel] = $content;
                $target = $this->dir . '/' . $rel;
                (new Filesystem())->ensureDirectoryExists(dirname($target));
                file_put_contents($target, $content);
                @chmod($target, fileperms($source) & 0777);
            } else {
                $this->before[$rel] = null;
            }
        }
    }

    public function read(string $rel): ?string
    {
        $file = $this->dir . '/' . $rel;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function write(string $rel, ?string $content): void
    {
        $file = $this->dir . '/' . $rel;
        if ($content === null) {
            if (is_file($file)) {
                unlink($file);
            }

            return;
        }
        (new Filesystem())->ensureDirectoryExists(dirname($file));
        file_put_contents($file, $content);
    }

    public function path(string $rel): string
    {
        return $this->dir . '/' . $rel;
    }

    /** @return array<string, ?string> files whose staged content differs from vendor before the run */
    public function changedFiles(): array
    {
        $changed = [];
        foreach ($this->before as $rel => $before) {
            $now = $this->read($rel);
            if ($now !== $before) {
                $changed[$rel] = $now;
            }
        }

        return $changed;
    }
}
