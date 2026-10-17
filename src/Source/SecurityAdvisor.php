<?php

declare(strict_types=1);

namespace BroCode\MagentoPatches\Source;

use BroCode\MagentoPatches\Magento\MagentoInfo;
use BroCode\MagentoPatches\Magento\MagentoVersion;
use BroCode\MagentoPatches\Store\Label;
use BroCode\MagentoPatches\Store\PatchesFile;

/**
 * Decides whether a bulletin concerns the installed Magento, based on the versions Composer
 * actually installed (magento2-base / extension-b2b and their release dates) and on the
 * [SEC] entries already in patches.json.
 */
final class SecurityAdvisor
{
    public function __construct(
        private readonly MagentoInfo $info,
        private readonly PatchesFile $store,
    ) {
    }

    /** @return array{product: string, bound: MagentoVersion, solution: ?MagentoVersion, action: string, applied: bool, components: string[]}|null */
    public function evaluate(Bulletin $bulletin): ?array
    {
        $components = [];
        $core = $this->info->version();
        $product = $this->info->editionLabel();
        $bound = $bulletin->affectedBound($product, $core->line);
        if ($bound !== null && $core->isCoveredBy($bound, $this->info->releaseDate())) {
            $components[] = 'core';
        }

        $b2b = $this->info->b2bVersion();
        $b2bBound = $b2b ? $bulletin->affectedBound('Adobe Commerce B2B', $b2b->line) : null;
        if ($b2b !== null && $b2bBound !== null && $b2b->isCoveredBy($b2bBound, $this->info->b2bPackage()?->getReleaseDate())) {
            $components[] = 'b2b';
        }
        if ($components === []) {
            return null;
        }

        if (!in_array('core', $components, true)) {
            $product = 'Adobe Commerce B2B';
            $bound = $b2bBound;
            $installed = $b2b;
        } else {
            $installed = $core;
        }
        $solution = $bulletin->solutionFor($product, $installed->line);
        $action = match (true) {
            $solution === null => 'hotfix',
            $solution->isDateBuild() => 'isolated',
            $solution->compareRank($installed) > 0 => 'upgrade',
            default => 'isolated',
        };

        return [
            'product' => $product,
            'bound' => $bound,
            'solution' => $solution,
            'action' => $action,
            'applied' => $this->store->managed(Label::SECURITY, $bulletin->id) !== [] || $this->store->isCovered($bulletin->id),
            'components' => $components,
        ];
    }
}
