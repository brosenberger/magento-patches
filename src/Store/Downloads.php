<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Store;

use BroCode\MagentoPatches\Source\Bulletin;

/**
 * patches/downloads/<ID>/ — the files exactly as downloaded from Adobe, committed with the
 * project. `composer patches:sync` registers whatever is stored here but not yet in
 * patches.json, so a CI pipeline can apply a download that was only committed.
 *
 * Accepted layouts:
 *   patches/downloads/APSB26-153/APSB26-153.zip          (written by patches:security)
 *   patches/downloads/APSB26-153/<any .zip/.patch/.diff>
 *   patches/downloads/APSB26-153_whatever.zip            (dropped in by hand; id from the name)
 */
final class Downloads
{
    private const EXTENSIONS = ['zip', 'patch', 'diff'];

    public readonly string $dir;

    public function __construct(string $dir)
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $real = realpath($dir);
        $this->dir = $real !== false ? str_replace('\\', '/', $real) : $dir;
    }

    public function manifestPath(): string
    {
        return $this->dir . '/manifest.json';
    }

    /** Path relative to the downloads folder, as used as key in the manifest. */
    public function rel(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $this->dir . '/') ? substr($path, strlen($this->dir) + 1) : basename($path);
    }

    public function abs(string $rel): string
    {
        return $this->dir . '/' . $rel;
    }

    /**
     * Compare what is stored for a bulletin with the manifest.
     *
     * @return array{ok: string[], changed: string[], new: string[], missing: string[]} manifest-relative paths
     */
    public function state(DownloadManifest $manifest, string $id): array
    {
        $state = ['ok' => [], 'changed' => [], 'new' => [], 'missing' => []];
        $known = $manifest->filesFor($id);
        foreach ($this->filesFor($id) as $file) {
            $rel = $this->rel($file);
            $entry = $known[$rel] ?? null;
            unset($known[$rel]);
            if ($entry === null) {
                $state['new'][] = $rel;
            } elseif (hash_file('sha256', $file) !== ($entry['sha256'] ?? null)) {
                $state['changed'][] = $rel;
            } else {
                $state['ok'][] = $rel;
            }
        }
        $state['missing'] = array_keys($known);

        return $state;
    }

    public static function detectId(string $name): ?string
    {
        return preg_match('/APSB\d{2}-\d+/i', $name, $m) ? Bulletin::normalizeId($m[0]) : null;
    }

    /** @return array<string, string[]> id => stored files; files whose id can't be told are under "" */
    public function all(): array
    {
        $out = [];
        if (!is_dir($this->dir)) {
            return $out;
        }
        foreach (scandir($this->dir) ?: [] as $entry) {
            $path = $this->dir . '/' . $entry;
            if ($entry[0] === '.') {
                continue;
            }
            if (is_dir($path)) {
                $files = $this->filesIn($path);
                if ($files !== []) {
                    $id = Bulletin::normalizeId($entry);
                    $out[$id] = array_merge($out[$id] ?? [], $files);
                }
            } elseif ($this->accepts($path)) {
                $out[self::detectId($entry) ?? ''][] = $path;
            }
        }
        uksort($out, static fn (string $a, string $b): int => [Bulletin::sortKey($a), $a] <=> [Bulletin::sortKey($b), $b]);

        return $out;
    }

    /** @return string[] */
    public function filesFor(string $id): array
    {
        return $this->all()[Bulletin::normalizeId($id)] ?? [];
    }

    public function dirFor(string $id): string
    {
        return $this->dir . '/' . Bulletin::normalizeId($id);
    }

    /**
     * Writes needed to keep the given inputs (zip, patch files, folder) under patches/downloads/<id>/.
     * Identical files already stored are not written again.
     *
     * @param string[] $inputs
     * @return array<string, string> absolute target path => content
     */
    public function planStore(string $id, array $inputs): array
    {
        $writes = [];
        $existing = [];
        foreach ($this->filesFor($id) as $file) {
            $existing[hash_file('sha256', $file)] = true;
        }
        foreach ($inputs as $input) {
            $real = realpath($input);
            if ($real === false || str_starts_with(str_replace('\\', '/', $real), $this->dir . '/')) {
                continue; // already stored
            }
            $files = is_dir($real) ? $this->filesIn($real) : [$real];
            foreach ($files as $file) {
                $content = (string) file_get_contents($file);
                $hash = hash('sha256', $content);
                if (isset($existing[$hash])) {
                    continue;
                }
                $existing[$hash] = true;
                $target = $this->freeName($this->dirFor($id), basename($file), $writes);
                $writes[$target] = $content;
            }
        }

        return $writes;
    }

    /** @return string[] */
    private function filesIn(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($this->accepts((string) $file)) {
                $files[] = str_replace('\\', '/', (string) $file);
            }
        }
        sort($files);

        return $files;
    }

    private function accepts(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)
            && !str_starts_with(basename($file), '.');
    }

    /** @param array<string, string> $planned */
    private function freeName(string $dir, string $name, array $planned): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        for ($n = 1; ; $n++) {
            $candidate = sprintf('%s/%s%s%s', $dir, $stem, $n > 1 ? '-' . $n : '', $ext !== '' ? '.' . $ext : '');
            if (!file_exists($candidate) && !isset($planned[$candidate])) {
                return $candidate;
            }
        }
    }
}
