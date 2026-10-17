<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Source;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * An Adobe KB article (experienceleague.adobe.com/…/kbarticles/ka-NNNNN) that links the isolated
 * patch files, e.g. https://repo.magento.com/patch/2-4-7-p10-oct-2026.zip.
 */
final class KbArticle
{
    /**
     * @param list<array{name: string, url: string}> $files
     * @param string[] $related KB articles announcing updated/replacement patches
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $lastUpdate,
        public readonly array $files,
        public readonly array $related,
    ) {
    }

    public static function parse(string $url, string $html): self
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        $lastUpdate = null;
        foreach (['last-update', 'meta-last-update', 'lastModifiedDate', 'article:modified_time'] as $name) {
            $node = $xpath->query(sprintf('//meta[@name="%1$s" or @property="%1$s"]/@content', $name))?->item(0);
            if ($node !== null && trim($node->nodeValue ?? '') !== '') {
                $lastUpdate = trim((string) $node->nodeValue);
                break;
            }
        }
        if ($lastUpdate === null && preg_match('/Last update:\s*([A-Z][a-z]+ \d{1,2}, \d{4})/', $doc->textContent, $m)) {
            $lastUpdate = $m[1];
        }

        $files = [];
        $related = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $a) {
            /** @var DOMElement $a */
            $href = self::absolute(trim($a->getAttribute('href')), $url);
            $path = (string) parse_url($href, PHP_URL_PATH);
            if (preg_match('/\.(zip|patch|diff)$/i', $path)) {
                $files[$href] = ['name' => basename($path), 'url' => $href];
                continue;
            }
            $text = trim(preg_replace('/\s+/', ' ', $a->textContent) ?? '');
            if (str_contains($href, '/kbarticles/') && $href !== $url && preg_match('/updat|replac|revis|re-?releas|corrected|new version/i', $text)) {
                $related[$href] = $href;
            }
        }

        return new self($url, $lastUpdate, array_values($files), array_values($related));
    }

    private static function absolute(string $href, string $base): string
    {
        $href = preg_replace('/#.*$/', '', $href) ?? $href;
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        return $origin . rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . $href;
    }
}
