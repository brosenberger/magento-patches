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

namespace BroCode\MagentoPatches\Source;

use BroCode\MagentoPatches\Magento\MagentoInfo;
use BroCode\MagentoPatches\Magento\MagentoVersion;
use Composer\Util\Filesystem;
use RuntimeException;
use ZipArchive;

/**
 * Collects the patch files from what the user downloaded (zip, .patch/.diff, or a folder)
 * and picks the ones for the installed version: Adobe ships one file per release
 * (VULN-39341_2.4.7-p10.patch, …-2.4.7-2026-jul.patch) and per edition.
 */
final class IsolatedPatchFiles
{
    public function __construct(private readonly string $workDir)
    {
        (new Filesystem())->ensureDirectoryExists($workDir);
    }

    /**
     * @param string[] $inputs
     * @return string[] absolute paths of all patch files found
     */
    public function collect(array $inputs): array
    {
        $files = [];
        foreach ($inputs as $input) {
            if (!file_exists($input)) {
                throw new RuntimeException(sprintf('File not found: %s', $input));
            }
            if (is_dir($input)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($input, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($this->isPatch((string) $file)) {
                        $files[] = (string) $file;
                    } elseif (strtolower(pathinfo((string) $file, PATHINFO_EXTENSION)) === 'zip') {
                        array_push($files, ...$this->extract((string) $file));
                    }
                }
            } elseif (strtolower(pathinfo($input, PATHINFO_EXTENSION)) === 'zip') {
                array_push($files, ...$this->extract($input));
            } else {
                $files[] = (string) realpath($input);
            }
        }
        if ($files === []) {
            throw new RuntimeException('No .patch/.diff files found in ' . implode(', ', $inputs));
        }

        return array_values(array_unique($files));
    }

    /**
     * @param string[] $files
     * @return array{files: string[], notes: string[]}
     */
    public function select(array $files, MagentoInfo $info, ?MagentoVersion $latestRelease): array
    {
        $version = $info->version();
        $notes = [];
        $byPattern = static fn (string $pattern): array => array_values(array_filter(
            $files,
            static fn (string $f): bool => (bool) preg_match($pattern, basename($f))
        ));

        // 1. Files for exactly the installed version.
        $selected = $byPattern($version->fileNamePattern());

        // 2. Monthly builds for the installed line (only valid on top of the latest -p).
        if ($selected === []) {
            $line = preg_quote(str_replace('.', '§', $version->line), '#');
            $selected = $byPattern('#(?<![0-9])' . str_replace('§', '[._-]', $line) . '[._-]\d{4}[._-][a-z]{3}#i');
            if ($selected !== []) {
                $notes[] = sprintf('Using monthly-build files for %s. Adobe tests them on the latest -p only%s.', $version->line, $latestRelease !== null
                    ? ($latestRelease->compareRank($version) > 0 ? sprintf(' — you are on %s, latest is %s', $version, $latestRelease) : sprintf(' (you are on %s)', $version))
                    : '');
            }
        }

        // 3. Files without any version in the name.
        if ($selected === []) {
            $selected = array_values(array_filter($files, static fn (string $f): bool => !preg_match('/\d+[._-]\d+[._-]\d+/', basename($f))));
        }

        // B2B files for the installed B2B version.
        $b2b = $info->b2bVersion();
        if ($b2b !== null) {
            $selected = array_merge($selected, $byPattern($b2b->fileNamePattern()));
        }
        $selected = array_values(array_unique($selected));

        if ($selected === []) {
            throw new RuntimeException(sprintf(
                "None of the patch files is for %s:\n  - %s",
                $info->describe(),
                implode("\n  - ", array_map('basename', $files))
            ));
        }
        usort($selected, fn (string $a, string $b): int => [$this->editionOrder($a), basename($a)] <=> [$this->editionOrder($b), basename($b)]);

        return ['files' => $selected, 'notes' => $notes];
    }

    /**
     * Which download links (by file name) are for the installed versions: exact version first,
     * else the monthly builds of the line; B2B files for the installed B2B version; if no name
     * carries a version at all, all of them.
     *
     * @param list<array{name: string, url: string}> $links
     * @return list<array{name: string, url: string}>
     */
    public function relevantLinks(array $links, MagentoInfo $info): array
    {
        $version = $info->version();
        $match = static fn (string $pattern): array => array_values(array_filter($links, static fn (array $l): bool => (bool) preg_match($pattern, $l['name'])));
        $selected = $match($version->fileNamePattern());
        if ($selected === []) {
            $line = str_replace('§', '[._-]', preg_quote(str_replace('.', '§', $version->line), '#'));
            $selected = $match('#(?<![0-9])' . $line . '[._-](?:\d{4}[._-][a-z]{3}|[a-z]{3}[._-]\d{4})#i');
        }
        $b2b = $info->b2bVersion();
        if ($b2b !== null) {
            $selected = array_merge($selected, $match($b2b->fileNamePattern()));
        }
        if ($selected === [] && array_filter($links, static fn (array $l): bool => (bool) preg_match('/\d+[._-]\d+[._-]\d+/', $l['name'])) === []) {
            $selected = $links;
        }
        $unique = [];
        foreach ($selected as $link) {
            $unique[$link['url']] = $link;
        }

        return array_values($unique);
    }

    /** Open Source files first (Adobe: "start with the CE file"), then Commerce, then B2B. */
    private function editionOrder(string $file): int
    {
        $name = strtolower(basename($file));

        return match (true) {
            (bool) preg_match('/(^|[^a-z])b2b([^a-z]|$)/', $name) => 2,
            (bool) preg_match('/(^|[^a-z])(ce|os|oss|community|open[-_]?source)([^a-z]|$)/', $name) => 0,
            (bool) preg_match('/(^|[^a-z])(ee|commerce|enterprise|ac)([^a-z]|$)/', $name) => 1,
            default => 0,
        };
    }

    /** @return string[] */
    private function extract(string $zipFile): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Cannot open zip ' . $zipFile);
        }
        $dir = $this->workDir . '/' . pathinfo($zipFile, PATHINFO_FILENAME) . '-' . substr(md5($zipFile . microtime()), 0, 6);
        (new Filesystem())->ensureDirectoryExists($dir);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/') || !$this->isPatch($name) || str_starts_with(basename($name), '._')) {
                continue;
            }
            // Folders are folded into the file name (CE/x.patch → CE__x.patch): keeps edition hints,
            // avoids clashes, and no path from the archive ever reaches the file system.
            $parts = array_filter(explode('/', str_replace('\\', '/', $name)), static fn (string $p): bool => $p !== '' && $p !== '.' && $p !== '..');
            $target = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]+/', '_', implode('__', $parts));
            file_put_contents($target, (string) $zip->getFromIndex($i));
            $files[] = $target;
        }
        $zip->close();

        return $files;
    }

    private function isPatch(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['patch', 'diff'], true);
    }
}
