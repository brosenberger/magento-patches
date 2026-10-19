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

use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use RuntimeException;
use Throwable;

/**
 * Reads Adobe Commerce security bulletins from helpx.adobe.com. There is no API, so this parses
 * the public pages. Only BulletinCatalog calls it, and only for what is not stored yet.
 */
final class BulletinRepository
{
    public const INDEX_URL = 'https://helpx.adobe.com/security/products/magento.html';
    /** Where Adobe publishes Commerce bulletins since 2026; older ones are only on helpx. */
    public const BULLETIN_URL = 'https://www.adobe.com/trust/security/products/commerce/%s.html';
    public const LEGACY_BULLETIN_URL = 'https://helpx.adobe.com/security/products/magento/%s.html';
    /** Adobe answers 403 to Composer's own User-Agent; this one names the tool honestly. */
    public const USER_AGENT = 'brocode-magento-patches (+https://github.com/brosenberger/magento-patches)';
    /** Bulletin numbers after the newest listed one that are probed (the helpx index stopped listing new ones). */
    private const PROBE_AHEAD = 60;

    public function __construct(private readonly HttpDownloader $http, private readonly ?Loop $loop = null)
    {
    }

    /** @return string[] bulletin ids (APSBYY-NN): the helpx index plus newer Commerce bulletins found by probing */
    public function index(): array
    {
        $html = $this->fetch(self::INDEX_URL);
        preg_match_all('/apsb(\d{2})-(\d+)/i', $html, $m, PREG_SET_ORDER);
        $ids = [];
        foreach ($m as $match) {
            $ids[sprintf('APSB%s-%02d', $match[1], (int) $match[2])] = true;
        }
        if ($ids === []) {
            throw new RuntimeException('no bulletin ids found on ' . self::INDEX_URL . ' — has the page layout changed? Use --bulletin=APSBYY-NN.');
        }
        foreach ($this->probeNewer(array_keys($ids)) as $id) {
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    public function bulletin(string $id): Bulletin
    {
        $url = sprintf(self::BULLETIN_URL, strtolower($id));
        try {
            $html = $this->fetch($url);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), '404')) {
                throw $e;
            }
            $url = sprintf(self::LEGACY_BULLETIN_URL, strtolower($id));
            $html = $this->fetch($url);
        }

        return (new BulletinParser())->parse($id, $url, $html);
    }

    /**
     * Commerce bulletins newer than the newest listed id of the current year: numbers are shared by all
     * Adobe products, and only Commerce bulletins exist under the Commerce path (others are 404).
     *
     * @param string[] $known
     * @return string[]
     */
    private function probeNewer(array $known): array
    {
        $year = (int) date('y');
        $max = 0;
        foreach ($known as $id) {
            if (preg_match('/^APSB(\d{2})-(\d+)$/', $id, $m) && (int) $m[1] === $year) {
                $max = max($max, (int) $m[2]);
            }
        }
        $found = [];
        $promises = [];
        for ($n = $max + 1; $n <= $max + self::PROBE_AHEAD; $n++) {
            $id = sprintf('APSB%02d-%02d', $year, $n);
            $promises[] = $this->http->add(sprintf(self::BULLETIN_URL, strtolower($id)), $this->options())
                ->then(static function () use (&$found, $id): void {
                    $found[] = $id;
                }, static function (): void {
                    // 404: no Commerce bulletin with this number
                });
        }
        if ($this->loop !== null) {
            $this->loop->wait($promises);
        } else {
            $this->http->wait();
        }
        sort($found, SORT_NATURAL);

        return $found;
    }

    private function fetch(string $url): string
    {
        try {
            return (string) $this->http->get($url, $this->options())->getBody();
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('could not fetch %s: %s', $url, $e->getMessage()), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return ['http' => ['header' => ['Accept: text/html', 'User-Agent: ' . self::USER_AGENT]]];
    }
}
