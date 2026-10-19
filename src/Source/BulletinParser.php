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

use BroCode\MagentoPatches\Magento\MagentoVersion;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Parses an Adobe security bulletin page (helpx.adobe.com/security/products/magento/apsbYY-NN.html).
 *
 * Relies on the two tables every Commerce bulletin has:
 *   Affected Versions: Product | Version | (Priority) | Platform   — "2.4.7-p10 and earlier", "2.4.7-2026-aug and earlier"
 *   Solution:          Product | Updated Version | Platform | Priority | Installation Instructions (link)
 */
final class BulletinParser
{
    /** Scheme and host of the page being parsed, for relative links. */
    private string $base = 'https://helpx.adobe.com';

    public const PRODUCTS = ['Adobe Commerce B2B', 'Adobe Commerce', 'Magento Open Source'];

    public function parse(string $id, string $url, string $html): Bulletin
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->separateBlocks($doc);

        $affected = [];
        $solution = [];
        $date = $priority = null;
        $cves = [];
        $this->base = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $url);

        // helpx.adobe.com used <table>; www.adobe.com/trust uses <div class="table"> with div rows/cells.
        $xpath = new DOMXPath($doc);
        $tables = $xpath->query('//table|//div[contains(concat(" ", normalize-space(@class), " "), " table ")]') ?: [];
        foreach ($tables as $table) {
            $rows = $this->rows($table);
            if ($rows === []) {
                continue;
            }
            $header = strtolower(implode(' | ', array_column($rows[0], 'text')));

            if (str_contains($header, 'bulletin id') && isset($rows[1])) {
                foreach ($rows[1] as $i => $cell) {
                    $name = strtolower($rows[0][$i]['text'] ?? '');
                    if (str_contains($name, 'date')) {
                        $date = $cell['text'];
                    } elseif (str_contains($name, 'priority')) {
                        $priority = $cell['text'];
                    }
                }
                continue;
            }
            if (str_contains($header, 'updated version')) {
                foreach (array_slice($rows, 1) as $row) {
                    $text = $row[1]['text'] ?? '';
                    $links = [];
                    foreach ($row as $cell) {
                        array_push($links, ...$cell['links']);
                    }
                    foreach ($this->products($row[0]['text'] ?? '') as $product) {
                        $solution[$product] = ['tokens' => $this->solutionTokens($text), 'text' => $text, 'links' => array_values(array_unique($links))];
                    }
                }
                continue;
            }
            if (str_contains($header, 'product') && str_contains($header, 'version')) {
                foreach (array_slice($rows, 1) as $row) {
                    foreach ($this->products($row[0]['text'] ?? '') as $product) {
                        $affected[$product] = $this->affectedTokens($row[1]['text'] ?? '');
                    }
                }
                continue;
            }
        }

        $text = MagentoVersion::normalize($doc->textContent);
        if ($date === null && preg_match('/(january|february|march|april|may|june|july|august|september|october|november|december) \d{1,2}, \d{4}/', $text, $m)) {
            $date = ucfirst($m[0]);
        }
        if (preg_match_all('/cve-\d{4}-\d{4,}/', $text, $m)) {
            $cves = array_values(array_unique(array_map('strtoupper', $m[0])));
        }
        $exploited = (bool) preg_match('/aware of[^.]{0,80}exploited in the wild/', $text)
            && !preg_match('/not aware of any exploits/', $text);

        if ($affected === []) {
            // A layout change must end as "could not check", never as "not affected".
            throw new RuntimeException(sprintf('no affected versions found on %s — has the page layout changed?', $url));
        }

        return new Bulletin(Bulletin::normalizeId($id), $url, $date, $priority, $affected, $solution, $cves, $exploited);
    }

    /** @return list<array{version: MagentoVersion, andEarlier: bool}> */
    private function affectedTokens(string $text): array
    {
        $tokens = [];
        $text = MagentoVersion::normalize($text);
        if (preg_match_all('/(' . MagentoVersion::TOKEN . ')(\s+and\s+earlier)?/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $v = MagentoVersion::parse($match[1]);
                if ($v !== null) {
                    $tokens[] = ['version' => $v, 'andEarlier' => !empty($match[2])];
                }
            }
        }

        return $tokens;
    }

    /** "2.4.8-p4 for 2.4.8-p3 and earlier" or plain "2.4.7-2026-jul". @return list<array{version: MagentoVersion, for: ?MagentoVersion}> */
    private function solutionTokens(string $text): array
    {
        $tokens = [];
        $text = MagentoVersion::normalize($text);
        $pattern = '/(' . MagentoVersion::TOKEN . ')(?:\s+for\s+(' . MagentoVersion::TOKEN . '))?/i';
        if (preg_match_all($pattern, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $v = MagentoVersion::parse($match[1]);
                if ($v !== null) {
                    $tokens[] = ['version' => $v, 'for' => isset($match[2]) && $match[2] !== '' ? MagentoVersion::parse($match[2]) : null];
                }
            }
        }

        return $tokens;
    }

    /**
     * Products named in a cell — one ("Magento Open Source") or several ("Adobe Commerce and Magento
     * Open Source", as hotfix bulletins write it).
     *
     * @return string[]
     */
    private function products(string $cell): array
    {
        $cell = trim(preg_replace('/\s+/u', ' ', $cell) ?? '');
        $found = [];
        foreach (preg_split('/\s*(?:,|&|\band\b)\s*/i', $cell) ?: [] as $part) {
            foreach (self::PRODUCTS as $product) {
                if (strcasecmp(trim($part), $product) === 0) {
                    $found[] = $product;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<list<array{text: string, links: string[]}>> */
    private function rows(DOMElement $table): array
    {
        $rows = [];
        $xpath = new DOMXPath($table->ownerDocument);
        $div = $table->nodeName === 'div';
        foreach ($xpath->query($div ? './div' : './/tr', $table) ?: [] as $tr) {
            $cells = [];
            foreach ($xpath->query($div ? './div' : './td|./th', $tr) ?: [] as $cell) {
                $links = [];
                foreach ($xpath->query('.//a[@href]', $cell) ?: [] as $a) {
                    /** @var DOMElement $a */
                    $links[] = $this->absolute($a->getAttribute('href'));
                }
                $cells[] = ['text' => trim(preg_replace('/[ \t]+/', ' ', str_replace("\u{00A0}", ' ', $cell->textContent)) ?? ''), 'links' => $links];
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /** Insert line breaks for <br>, <p>, <li>, <div> so "2.4.9<br>2.4.8-p5" doesn't become "2.4.92.4.8-p5". */
    private function separateBlocks(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//br|//p|//li|//div') ?: [] as $node) {
            $node->parentNode?->insertBefore($doc->createTextNode("\n"), $node->nextSibling);
            if ($node->nodeName !== 'br') {
                $node->insertBefore($doc->createTextNode("\n"), $node->firstChild);
            }
        }
    }

    private function absolute(string $href): string
    {
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $this->base . $href;
        }

        return $href;
    }
}
