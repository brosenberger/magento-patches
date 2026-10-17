<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Store;

use Composer\Util\Filesystem;

/**
 * Remembers the original content of every file it is asked to touch, so a failed run
 * can put patches.json, patches.lock.json, composer.json/lock and patch files back exactly.
 */
final class Transaction
{
    /** @var array<string, ?string> absolute path => original content (null = did not exist) */
    private array $originals = [];

    public function track(string $path): void
    {
        if (!array_key_exists($path, $this->originals)) {
            $this->originals[$path] = is_file($path) ? (string) file_get_contents($path) : null;
        }
    }

    public function write(string $path, string $content): void
    {
        $this->track($path);
        (new Filesystem())->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);
    }

    public function delete(string $path): void
    {
        $this->track($path);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function isDirty(): bool
    {
        foreach ($this->originals as $path => $content) {
            $now = is_file($path) ? (string) file_get_contents($path) : null;
            if ($now !== $content) {
                return true;
            }
        }

        return false;
    }

    public function rollback(): void
    {
        foreach ($this->originals as $path => $content) {
            if ($content === null) {
                if (is_file($path)) {
                    unlink($path);
                }
                $this->pruneEmptyDirs(dirname($path));
                continue;
            }
            (new Filesystem())->ensureDirectoryExists(dirname($path));
            file_put_contents($path, $content);
        }
        $this->originals = [];
    }

    public function commit(): void
    {
        foreach ($this->originals as $path => $content) {
            if (!is_file($path)) {
                $this->pruneEmptyDirs(dirname($path));
            }
        }
        $this->originals = [];
    }

    private function pruneEmptyDirs(string $dir): void
    {
        // Only clean up inside the patches/ folder the plugin owns.
        while (str_contains($dir, '/patches/') && is_dir($dir) && count(scandir($dir) ?: []) === 2) {
            rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
