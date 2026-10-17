<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Source;

use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use RuntimeException;
use Throwable;

/**
 * Downloads through Composer's HTTP client, so repo.magento.com credentials from auth.json
 * (the Magento access keys) and proxy settings are used automatically.
 */
final class Fetcher
{
    /** @var array<string, KbArticle> */
    private array $kbCache = [];

    public function __construct(
        private readonly HttpDownloader $http,
        private readonly string $dir,
    ) {
        (new Filesystem())->ensureDirectoryExists($dir);
    }

    public static function isUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    /** @return string local file path, named after the URL */
    public function download(string $url): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'download';
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'download';
        $dir = $this->dir . '/' . substr(hash('sha256', $url), 0, 12);
        (new Filesystem())->ensureDirectoryExists($dir);
        $target = $dir . '/' . $name;
        try {
            $body = (string) $this->http->get($url)->getBody();
        } catch (Throwable $e) {
            $hint = str_contains($url, 'repo.magento.com') ? ' (are the Magento access keys for repo.magento.com in auth.json?)' : '';
            throw new RuntimeException(sprintf('could not download %s: %s%s', $url, $e->getMessage(), $hint), 0, $e);
        }
        if ($body === '' || preg_match('/^\s*<(!doctype|html)/i', substr($body, 0, 200))) {
            throw new RuntimeException(sprintf('%s did not return a patch file (login page?)', $url));
        }
        file_put_contents($target, $body);

        return $target;
    }

    public function kb(string $url): KbArticle
    {
        if (!isset($this->kbCache[$url])) {
            try {
                $html = (string) $this->http->get($url, ['http' => ['header' => ['Accept: text/html']]])->getBody();
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf('could not fetch %s: %s', $url, $e->getMessage()), 0, $e);
            }
            $this->kbCache[$url] = KbArticle::parse($url, $html);
        }

        return $this->kbCache[$url];
    }

    /**
     * A bulletin's KB articles plus the "updated patch" articles they link to (one level).
     *
     * @param string[] $urls
     * @return KbArticle[]
     */
    public function kbWithRelated(array $urls): array
    {
        $articles = [];
        foreach ($urls as $url) {
            if (!str_contains($url, '/kbarticles/')) {
                continue; // release notes, upgrade guides
            }
            $article = $this->kb($url);
            $articles[$url] = $article;
            foreach ($article->related as $related) {
                $articles[$related] ??= $this->kb($related);
            }
        }

        return array_values($articles);
    }
}
