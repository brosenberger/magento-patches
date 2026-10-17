<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Source;

use Composer\Util\HttpDownloader;
use RuntimeException;
use Throwable;

/**
 * Reads Adobe Commerce security bulletins from helpx.adobe.com. There is no API, so this parses
 * the public pages. Only BulletinCatalog calls it, and only for what is not stored yet.
 */
final class BulletinRepository
{
    public const INDEX_URL = 'https://helpx.adobe.com/security/products/magento.html';
    public const BULLETIN_URL = 'https://helpx.adobe.com/security/products/magento/%s.html';

    public function __construct(private readonly HttpDownloader $http)
    {
    }

    /** @return string[] bulletin ids (APSBYY-NN) listed on the Commerce index page */
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

        return array_keys($ids);
    }

    public function bulletin(string $id): Bulletin
    {
        $url = sprintf(self::BULLETIN_URL, strtolower($id));

        return (new BulletinParser())->parse($id, $url, $this->fetch($url));
    }

    private function fetch(string $url): string
    {
        try {
            return (string) $this->http->get($url, ['http' => ['header' => ['Accept: text/html']]])->getBody();
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('could not fetch %s: %s', $url, $e->getMessage()), 0, $e);
        }
    }
}
