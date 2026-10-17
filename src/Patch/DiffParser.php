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

namespace BroCode\MagentoPatches\Patch;

use RuntimeException;

/**
 * Splits a unified diff (git style or plain `diff -u`) into one Chunk per file.
 * Hunks are consumed by their line counts, so content lines that happen to look
 * like headers ("--- ", "diff --git") never start a new file by accident.
 */
final class DiffParser
{
    /** Top-level directories of a Magento project; paths starting with these carry no a/ b/ prefix. */
    private const PROJECT_ROOTS = ['vendor', 'app', 'lib', 'setup', 'pub', 'bin', 'dev', 'generated', 'var', 'update', 'phpserver'];

    /** @return Chunk[] */
    public function parse(string $text): array
    {
        $lines = preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if (str_starts_with($line, 'diff --git ')) {
                $chunks[] = $this->parseFile($lines, $i, true);
                continue;
            }
            if (str_starts_with($line, '--- ') && str_starts_with($lines[$i + 1] ?? '', '+++ ')
                && str_starts_with($lines[$i + 2] ?? '', '@@')) {
                $chunks[] = $this->parseFile($lines, $i, false);
                continue;
            }
            $i++; // preamble, mail headers, trailers ("-- \n2.43.0")
        }

        return $chunks;
    }

    /** @param string[] $lines */
    private function parseFile(array $lines, int &$i, bool $git): Chunk
    {
        $n = count($lines);
        $old = $new = null;
        $headerOld = $headerNew = null;
        $renameFrom = $renameTo = null;
        $isNew = $isDeleted = $binary = $copy = false;
        $extended = [];

        if ($git) {
            [$headerOld, $headerNew] = $this->gitHeaderPaths(rtrim($lines[$i], "\r\n"));
            $i++;
        }

        // Header lines up to the first hunk.
        while ($i < $n && !str_starts_with($lines[$i], '@@')) {
            $line = rtrim($lines[$i], "\r\n");
            if ($git && str_starts_with($line, 'diff --git ')) {
                break; // next file, this one has no hunks (mode change, rename, binary)
            }
            if (!$git && !str_starts_with($line, '--- ') && !str_starts_with($line, '+++ ')) {
                break;
            }
            if (str_starts_with($line, '--- ')) {
                $old = $this->path(substr($line, 4));
            } elseif (str_starts_with($line, '+++ ')) {
                $new = $this->path(substr($line, 4));
            } elseif (str_starts_with($line, 'rename from ')) {
                $renameFrom = $this->unquote(substr($line, 12));
            } elseif (str_starts_with($line, 'rename to ')) {
                $renameTo = $this->unquote(substr($line, 10));
            } elseif (str_starts_with($line, 'copy from ') || str_starts_with($line, 'copy to ')) {
                $copy = true;
            } elseif (str_starts_with($line, 'new file mode')) {
                $isNew = true;
                $extended[] = $line;
            } elseif (str_starts_with($line, 'deleted file mode')) {
                $isDeleted = true;
                $extended[] = $line;
            } elseif (str_starts_with($line, 'GIT binary patch') || str_starts_with($line, 'Binary files ')) {
                $binary = true;
            } elseif ($line !== '' && !$binary) {
                $extended[] = $line; // index, old/new mode, (dis)similarity index
            }
            $i++;
        }

        $hunks = [];
        while ($i < $n && str_starts_with($lines[$i], '@@')) {
            $hunks[] = $this->parseHunk($lines, $i);
        }

        // Resolve the paths: ---/+++ win, then rename lines, then the diff --git header.
        $oldPath = $old ?? $renameFrom ?? $headerOld;
        $newPath = $new ?? $renameTo ?? $headerNew;
        if ($old === false || $isNew) {
            $oldPath = null;
        }
        if ($new === false || $isDeleted) {
            $newPath = null;
        }
        if ($oldPath === null && $newPath === null) {
            throw new RuntimeException('Patch contains a file section without any path.');
        }
        // Mode-only header lines are meaningless once we re-render; keep them anyway for git.
        $extended = array_values(array_filter($extended, static fn (string $l): bool => !str_starts_with($l, 'similarity index')
            && !str_starts_with($l, 'dissimilarity index')));

        return new Chunk(
            $oldPath === false ? null : $oldPath,
            $newPath === false ? null : $newPath,
            $extended,
            $hunks,
            $binary,
            $copy,
        );
    }

    /** @param string[] $lines */
    private function parseHunk(array $lines, int &$i): Hunk
    {
        $header = $lines[$i];
        if (!preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $header, $m)) {
            throw new RuntimeException('Malformed hunk header: ' . trim($header));
        }
        $oldStart = (int) $m[1];
        $oldLeft = $oldCount = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
        $newLeft = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1;
        $i++;

        $body = [];
        $n = count($lines);
        while ($i < $n && ($oldLeft > 0 || $newLeft > 0)) {
            $line = $lines[$i];
            $c = $line[0];
            if ($line === "\n" || $line === "\r\n") {
                $line = ' ' . $line; // context line whose trailing space was stripped by an editor
                $c = ' ';
            }
            if ($c === ' ') {
                $oldLeft--;
                $newLeft--;
            } elseif ($c === '-') {
                $oldLeft--;
            } elseif ($c === '+') {
                $newLeft--;
            } elseif ($c !== '\\') {
                throw new RuntimeException('Hunk ends early, unexpected line: ' . rtrim($line));
            }
            $body[] = $line;
            $i++;
        }
        if ($oldLeft > 0 || $newLeft > 0) {
            throw new RuntimeException('Patch is truncated: hunk ' . trim($header) . ' is incomplete.');
        }
        while ($i < $n && str_starts_with($lines[$i], '\\')) {
            $body[] = $lines[$i++]; // "\ No newline at end of file"
        }

        return new Hunk($header, $body, $oldStart, $oldCount);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function gitHeaderPaths(string $line): array
    {
        $rest = substr($line, strlen('diff --git '));
        if (preg_match('#^"?a/(.+?)"? "?b/(.+?)"?$#', $rest, $m)) {
            return [$this->unquote($m[1]), $this->unquote($m[2])];
        }
        $parts = explode(' ', $rest);

        return [isset($parts[0]) ? $this->path($parts[0]) : null, isset($parts[1]) ? $this->path($parts[1]) : null];
    }

    /** Path from a ---/+++ line, false for /dev/null. Strips the a/ b/ prefix (depth 1). */
    private function path(string $raw): string|false
    {
        $raw = explode("\t", rtrim($raw, "\r\n"))[0];
        $raw = $this->unquote(trim($raw));
        if ($raw === '/dev/null') {
            return false;
        }
        $first = strstr($raw, '/', true);
        if ($first !== false && !in_array($first, self::PROJECT_ROOTS, true)) {
            $raw = substr($raw, strlen($first) + 1);
        }

        return ltrim($raw, '/');
    }

    private function unquote(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '"') && str_ends_with($path, '"')) {
            $path = stripcslashes(substr($path, 1, -1));
        }

        return $path;
    }
}
