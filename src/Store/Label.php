<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Store;

/**
 * Descriptions written into patches.json: "[SEC] APSB26-153: VULN-39341_2.4.7-p10.patch".
 * The tag marks entries this plugin manages; anything else in patches.json is left untouched.
 */
final class Label
{
    public const SECURITY = 'SEC';
    public const QUALITY = 'QPT';
    public const CUSTOM = 'CUSTOM';
    public const TAGS = [self::SECURITY, self::QUALITY, self::CUSTOM];

    public static function make(string $tag, string $id, string $detail = '', string $suffix = ''): string
    {
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
        if (mb_strlen($detail) > 90) {
            $detail = rtrim(mb_substr($detail, 0, 87)) . '…';
        }
        $detail = trim($detail . ($suffix !== '' ? ' [' . $suffix . ']' : ''));

        return sprintf('[%s] %s', $tag, $id) . ($detail !== '' ? ': ' . $detail : '');
    }

    /** @return array{tag: string, id: string, detail: string}|null */
    public static function parse(string $label): ?array
    {
        if (!preg_match('/^\[(' . implode('|', self::TAGS) . ')\] ([^:]+?)(?:: (.*))?$/s', $label, $m)) {
            return null;
        }

        return ['tag' => $m[1], 'id' => $m[2], 'detail' => $m[3] ?? ''];
    }

    public static function prefix(string $tag, ?string $id = null): string
    {
        return $id === null ? "[{$tag}] " : "[{$tag}] {$id}";
    }

    public static function matches(string $label, string $tag, ?string $id = null): bool
    {
        $parsed = self::parse($label);

        return $parsed !== null && $parsed['tag'] === $tag && ($id === null || strcasecmp($parsed['id'], $id) === 0);
    }
}
