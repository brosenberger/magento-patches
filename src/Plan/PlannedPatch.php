<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Plan;

final class PlannedPatch
{
    /** @param string[] $files package-relative files this patch changes */
    public function __construct(
        public readonly string $package,
        public readonly string $label,
        public readonly string $path,
        public readonly string $content,
        public readonly array $files,
    ) {
    }
}
