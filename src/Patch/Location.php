<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Patch;

final class Location
{
    public const PACKAGE = 'package';
    public const MAPPED = 'mapped';           // root file deployed from a package (magento2-base "map")
    public const NOT_INSTALLED = 'not-installed';
    public const UNSUPPORTED = 'unsupported';
    public const IGNORED = 'ignored';         // known to be no part of the fix (Adobe's vendor/bin/patch-status)

    public function __construct(
        public readonly string $status,
        public readonly ?string $package = null,
        public readonly ?string $installPath = null,
        public readonly ?string $relPath = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function isPatchable(): bool
    {
        return $this->status === self::PACKAGE || $this->status === self::MAPPED;
    }
}
