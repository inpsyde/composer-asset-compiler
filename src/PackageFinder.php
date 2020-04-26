<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryInterface;

class PackageFinder
{
    /**
     * @var array
     */
    private $packageData;

    /**
     * @var \Inpsyde\AssetsCompiler\EnvResolver
     */
    private $envResolver;

    /**
     * @var \Inpsyde\AssetsCompiler\Defaults
     */
    private $defaults;

    /**
     * @var bool
     */
    private $stopOnFailure;

    /**
     * @param array $packageData
     * @param \Inpsyde\AssetsCompiler\EnvResolver $envResolver
     * @param \Inpsyde\AssetsCompiler\Defaults $defaults
     * @param bool $stopOnFailure
     */
    public function __construct(
        array $packageData,
        EnvResolver $envResolver,
        Defaults $defaults,
        bool $stopOnFailure
    ) {

        $this->packageData = $packageData;
        $this->envResolver = $envResolver;
        $this->defaults = $defaults;
        $this->stopOnFailure = $stopOnFailure;
    }

    /**
     * @param RepositoryInterface $repository
     * @param RootPackageInterface $root
     * @param PackageFactory $packageFactory
     * @param bool $autoDiscover
     * @return array<string, Package>
     */
    public function find(
        RepositoryInterface $repository,
        RootPackageInterface $root,
        PackageFactory $packageFactory,
        bool $autoDiscover = true
    ): array {

        /**
         * @var array<string, PackageConfig> $rootLevelIncludePackagesConfig
         * @var array<int, string> $excludeNames
         */
        [$rootLevelIncludePackagesConfig, $excludeNames] = $this->extractRootLevelPackagesData();

        $found = [];

        $rootPackage = $this->attemptRootPackageFactory($root, $packageFactory);
        $rootPackage and $found[(string)$root->getName()] = $rootPackage;

        if (!$rootLevelIncludePackagesConfig && !$autoDiscover) {
            return $found;
        }

        /** @var array<int, string> $rootLevelIncludeNames */
        $rootLevelIncludeNames = array_keys($rootLevelIncludePackagesConfig);

        /** @var PackageInterface[] $composerPackages */
        $composerPackages = $repository->getPackages();

        foreach ($composerPackages as $composerPackage) {
            /** @var string $name */
            $name = $composerPackage->getName();
            if (
                $composerPackage === $root
                || isset($found[$name])
                || $this->nameMatches($name, ...$excludeNames)[0]
            ) {
                continue;
            }

            /** @var string|null $rootLevelPackagePattern */
            [, $rootLevelPackagePattern] = $this->nameMatches($name, ...$rootLevelIncludeNames);

            /** @var PackageConfig|null $rootLevelPackageConfig */
            $rootLevelPackageConfig = $rootLevelPackagePattern
                ? ($rootLevelIncludePackagesConfig[$rootLevelPackagePattern] ?? null)
                : null;

            // If there's no root-level config for the package, and auto-discover is disabled,
            // there's nothing else we should do.
            if (!$autoDiscover && !$rootLevelPackageConfig) {
                continue;
            }

            $package = $packageFactory->attemptFactory(
                $composerPackage,
                $rootLevelPackageConfig,
                $this->defaults
            );

            $requiredExplicitly = $rootLevelPackageConfig && ($rootLevelPackagePattern === $name);

            if (!$this->assertValidPackage($package, $name, $requiredExplicitly)) {
                continue;
            }

            $found[$name] = $package;
        }

        return $found;
    }

    /**
     * @param \Composer\Package\RootPackageInterface $root
     * @param \Inpsyde\AssetsCompiler\PackageFactory $packageFactory
     * @return \Inpsyde\AssetsCompiler\Package|null
     */
    private function attemptRootPackageFactory(
        RootPackageInterface $root,
        PackageFactory $packageFactory
    ): ?Package {

        $packageConfig = PackageConfig::forComposerPackage($root, $this->envResolver);
        if (!$packageConfig->isRunnable()) {
            return null;
        }

        $rootPackage = $packageFactory->attemptFactory($root, $packageConfig, Defaults::empty());
        if ($rootPackage && $rootPackage->isValid()) {
            return $rootPackage;
        }

        return null;
    }

    /**
     * @return array{0:array<string, PackageConfig>, 1:array<int, string>}
     */
    private function extractRootLevelPackagesData(): array
    {
        $packages = [];
        $exclude = [];

        foreach ($this->packageData as $key => $packageData) {
            $config = $this->factoryRootLevelPackageConfig([$key, $packageData]);
            if (!$config) {
                continue;
            }

            if ($config->isDisabled()) {
                $exclude[] = $key;
                continue;
            }

            $packages[$key] = $config;
        }

        return [$packages, $exclude];
    }

    /**
     * @param array $keyAndPackageData
     * @return \Inpsyde\AssetsCompiler\PackageConfig|null
     */
    private function factoryRootLevelPackageConfig(array $keyAndPackageData): ?PackageConfig
    {
        [$key, $packageData] = $keyAndPackageData;
        if (!$key || !is_string($key)) {
            if ($this->stopOnFailure) {
                throw new \Exception("invalid packages settings.");
            }

            return null;
        }

        $config = PackageConfig::forRawPackageData($packageData, $this->envResolver);
        if (!$config->isValid()) {
            if ($this->stopOnFailure) {
                throw new \Exception("Package setting for '{$key}' is not valid.");
            }

            return null;
        }

        return $config;
    }

    /**
     * @param string $name
     * @param string[] $patterns
     * @return array{0:null, 1:null}|array{0:string, 1:string}
     */
    private function nameMatches(string $name, string ...$patterns): array
    {
        foreach ($patterns as $pattern) {
            if (
                $pattern === $name
                || fnmatch($pattern, $name, FNM_PATHNAME | FNM_PERIOD | FNM_CASEFOLD)
            ) {
                return [$name, $pattern];
            }
        }

        return [null, null];
    }

    /**
     * @param Package $package
     * @param string $name
     * @param bool $requiredByRoot
     * @return bool
     */
    private function assertValidPackage(
        ?Package $package,
        string $name,
        bool $requiredByRoot
    ): bool {

        $valid = $package
            && $package->isValid()
            && file_exists(($package->path() ?? '.') . '/package.json');

        if (!$valid && $requiredByRoot && $this->stopOnFailure) {
            throw new \Exception("Could not find valid configuration for '{$name}'.");
        }

        return $valid;
    }
}
