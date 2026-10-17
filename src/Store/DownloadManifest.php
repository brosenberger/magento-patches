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

namespace BroCode\MagentoPatches\Store;

use DateTimeImmutable;
use RuntimeException;

/**
 * patches/downloads/manifest.json — sha256, size and source URL of every stored download, plus a
 * snapshot of the KB articles they came from. Lets the plugin notice when a stored file was
 * replaced (in the repo or by Adobe on the server).
 *
 *   "files": { "APSB26-153/2-4-7-p10-oct-2026.zip": { "id", "sha256", "size", "source", "storedAt", "checkedAt" } }
 *   "kb":    { "<kb url>": { "lastUpdate", "files": [{ "name", "url" }], "related": [url], "checkedAt" } }
 */
final class DownloadManifest
{
    /** @var array{files: array<string, array<string, mixed>>, kb: array<string, array<string, mixed>>} */
    private array $data = ['files' => [], 'kb' => []];
    private bool $exists;

    public function __construct(public readonly string $path)
    {
        $this->exists = is_file($path);
        if ($this->exists) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('%s is not valid JSON: %s', $path, json_last_error_msg()));
            }
            $this->data['files'] = (array) ($decoded['files'] ?? []);
            $this->data['kb'] = (array) ($decoded['kb'] ?? []);
        }
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /** @return array<string, mixed>|null */
    public function file(string $rel): ?array
    {
        return $this->data['files'][$rel] ?? null;
    }

    /** @return array<string, array<string, mixed>> rel => entry */
    public function filesFor(string $id): array
    {
        return array_filter($this->data['files'], static fn (array $e): bool => strcasecmp((string) ($e['id'] ?? ''), $id) === 0);
    }

    /** @return string[] */
    public function ids(): array
    {
        return array_values(array_unique(array_map(static fn (array $e): string => (string) ($e['id'] ?? ''), $this->data['files'])));
    }

    public function record(string $rel, string $id, string $content, ?string $source): void
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $old = $this->data['files'][$rel] ?? [];
        $sha = hash('sha256', $content);
        $this->data['files'][$rel] = [
            'id' => $id,
            'sha256' => $sha,
            'size' => strlen($content),
            'source' => $source ?? ($old['source'] ?? null),
            'storedAt' => ($old['sha256'] ?? null) === $sha ? ($old['storedAt'] ?? $now) : $now,
            'checkedAt' => $old['checkedAt'] ?? null,
        ];
        ksort($this->data['files']);
    }

    public function setSource(string $rel, string $source): void
    {
        if (isset($this->data['files'][$rel])) {
            $this->data['files'][$rel]['source'] = $source;
        }
    }

    public function touchChecked(string $rel): void
    {
        if (isset($this->data['files'][$rel])) {
            $this->data['files'][$rel]['checkedAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
        }
    }

    public function forget(string $rel): void
    {
        unset($this->data['files'][$rel]);
    }

    /** @return array<string, mixed>|null */
    public function kb(string $url): ?array
    {
        return $this->data['kb'][$url] ?? null;
    }

    /** @param list<array{name: string, url: string}> $files */
    public function setKb(string $url, ?string $lastUpdate, array $files, array $related): void
    {
        $this->data['kb'][$url] = [
            'lastUpdate' => $lastUpdate,
            'files' => $files,
            'related' => array_values($related),
            'checkedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
        ksort($this->data['kb']);
    }

    public function encode(): string
    {
        $data = [
            '_readme' => 'Hashes and sources of the files in patches/downloads/, maintained by brocode/magento-patches. Commit this file.',
            'files' => $this->data['files'] === [] ? new \stdClass() : $this->data['files'],
            'kb' => $this->data['kb'] === [] ? new \stdClass() : $this->data['kb'],
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
