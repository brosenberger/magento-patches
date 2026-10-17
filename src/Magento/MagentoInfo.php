<?php

declare(strict_types=1);

namespace Brocode\MagentoPatches\Magento;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use DateTimeInterface;
use RuntimeException;

/**
 * Everything about the installed Magento, read from Composer's local repository
 * (vendor/composer/installed.json) — i.e. what is actually installed right now,
 * not what composer.json asks for.
 */
final class MagentoInfo
{
    public const BASE_CE = 'magento/magento2-base';
    public const BASE_EE = 'magento/magento2-ee-base';
    public const BASE_B2B = 'magento/magento2-b2b-base';
    public const EXT_B2B = 'magento/extension-b2b';
    public const PRODUCTS = ['magento/product-enterprise-edition', 'magento/product-community-edition'];

    private InstalledRepositoryInterface $repo;
    private string $root;

    public function __construct(private readonly Composer $composer)
    {
        $this->repo = $composer->getRepositoryManager()->getLocalRepository();
        $this->root = rtrim(str_replace('\\', '/', (string) getcwd()), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function package(string $name): ?PackageInterface
    {
        return $this->repo->findPackage($name, '*');
    }

    public function isInstalled(string $name): bool
    {
        return $this->package($name) !== null;
    }

    /** @return PackageInterface[] */
    public function packages(): array
    {
        return $this->repo->getPackages();
    }

    public function requireBase(): PackageInterface
    {
        $base = $this->package(self::BASE_CE);
        if ($base === null) {
            throw new RuntimeException('magento/magento2-base is not installed — is this a Composer-based Magento 2 project?');
        }

        return $base;
    }

    public function edition(): string
    {
        return $this->isInstalled(self::BASE_EE) || $this->isInstalled(self::PRODUCTS[0]) ? 'ee' : 'ce';
    }

    public function editionLabel(): string
    {
        return $this->edition() === 'ee' ? 'Adobe Commerce' : 'Magento Open Source';
    }

    /** Installed core version, e.g. 2.4.7-p10, taken from the installed magento2-base package. */
    public function version(): MagentoVersion
    {
        $base = $this->requireBase();
        $version = MagentoVersion::parse($base->getPrettyVersion());
        if ($version === null) {
            throw new RuntimeException(sprintf('Cannot interpret installed magento2-base version "%s".', $base->getPrettyVersion()));
        }

        return $version;
    }

    public function releaseDate(): ?DateTimeInterface
    {
        return $this->requireBase()->getReleaseDate();
    }

    public function b2bPackage(): ?PackageInterface
    {
        return $this->package(self::EXT_B2B) ?? $this->package(self::BASE_B2B);
    }

    public function b2bVersion(): ?MagentoVersion
    {
        $pkg = $this->b2bPackage();

        return $pkg ? MagentoVersion::parse($pkg->getPrettyVersion()) : null;
    }

    /** The product metapackage the project depends on (needed for upgrades). */
    public function productPackage(): ?string
    {
        foreach (self::PRODUCTS as $name) {
            if ($this->isInstalled($name)) {
                return $name;
            }
        }

        return null;
    }

    public function installPath(PackageInterface $package): ?string
    {
        $path = $this->composer->getInstallationManager()->getInstallPath($package);
        if ($path === null || $path === '') {
            return null;
        }
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }

    public function vendorDir(): string
    {
        return rtrim(str_replace('\\', '/', (string) $this->composer->getConfig()->get('vendor-dir')), '/');
    }

    public function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        return str_starts_with($absolute, $this->root . '/') ? substr($absolute, strlen($this->root) + 1) : $absolute;
    }

    public function describe(): string
    {
        $text = sprintf('%s %s', $this->editionLabel(), $this->version());
        $date = $this->releaseDate();
        if ($date !== null) {
            $text .= sprintf(' (released %s)', $date->format('Y-m-d'));
        }
        $b2b = $this->b2bVersion();
        if ($b2b !== null) {
            $text .= sprintf(', B2B %s', $b2b);
        }

        return $text;
    }
}
