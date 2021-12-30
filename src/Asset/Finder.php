<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Util\ModeResolver;

class Finder
{
    /**
     * @var array
     */
    private $packagesData;

    /**
     * @var ModeResolver
     */
    private $modeResolver;

    /**
     * @var Defaults<Config|null|>
     */
    private $defaults;

    /**
     * @var Config
     */
    private $rootPackageConfig;

    /**
     * @var bool
     */
    private $stopOnFailure;

    /**
     * @param array $packageData
     * @param ModeResolver $modeResolver
     * @param Filesystem $filesystem
     * @param Defaults $defaults
     * @param string $rootDir
     * @param Config $rootPackageConfig
     * @param bool $stopOnFailure
     * @return Finder
     */
    public static function new(
        array $packageData,
        ModeResolver $modeResolver,
        Defaults $defaults,
        Config $rootPackageConfig,
        bool $stopOnFailure
    ): Finder {

        return new self(
            $packageData,
            $modeResolver,
            $defaults,
            $rootPackageConfig,
            $stopOnFailure
        );
    }

    /**
     * @param array $packageData
     * @param ModeResolver $modeResolver
     * @param Defaults $defaults
     * @param Config $rootPackageConfig
     * @param bool $stopOnFailure
     */
    private function __construct(
        array $packageData,
        ModeResolver $modeResolver,
        Defaults $defaults,
        Config $rootPackageConfig,
        bool $stopOnFailure
    ) {

        $this->packagesData = $modeResolver->removeModeConfig($packageData);
        $this->modeResolver = $modeResolver;
        $this->defaults = $defaults;
        $this->rootPackageConfig = $rootPackageConfig;
        $this->stopOnFailure = $stopOnFailure;
    }

    /**
     * @param RepositoryInterface $repository
     * @param RootPackageInterface $root
     * @param Factory $assetsFactory
     * @param bool $autoDiscover
     * @return array<string, Asset>
     */
    public function find(
        RepositoryInterface $repository,
        RootPackageInterface $root,
        Factory $assetsFactory,
        bool $autoDiscover = true
    ): array {

        [$rootLevelPackagesConfig, $excludeNames] = $this->extractRootLevelPackagesData();

        $found = [];

        $rootAsset = $this->attemptFactoryRootPackageAsset($root, $assetsFactory);
        $rootAsset and $found[$root->getName()] = $rootAsset;

        if (!$rootLevelPackagesConfig && !$autoDiscover) {
            return $found;
        }

        $rootLevelIncludePatterns = array_keys($rootLevelPackagesConfig);
        $packages = $repository->getPackages();

        foreach ($packages as $package) {
            $name = $package->getName();
            if (
                ($package === $root)
                || isset($found[$name])
                || $this->nameMatches($name, ...$excludeNames)[0]
            ) {
                continue;
            }

            [, $rootLevelPackagePattern] = $this->nameMatches($name, ...$rootLevelIncludePatterns);

            $rootLevelPackageConfig = $rootLevelPackagePattern
                ? ($rootLevelPackagesConfig[$rootLevelPackagePattern] ?? null)
                : null;

            // If there's no root-level config for the package, and auto-discover is disabled,
            // there's nothing else we should do.
            if (
                (!$autoDiscover && !$rootLevelPackageConfig)
                || ($rootLevelPackageConfig && $rootLevelPackageConfig->isDisabled())
            ) {
                continue;
            }

            $asset = $assetsFactory->attemptFactory(
                $package,
                $rootLevelPackageConfig,
                $this->defaults
            );

            $requiredExplicitly = $rootLevelPackageConfig && ($rootLevelPackagePattern === $name);

            if (!$this->assertValidAsset($asset, $name, $requiredExplicitly)) {
                continue;
            }

            $asset and $found[$name] = $asset;
        }

        if ($this->stopOnFailure) {
            $this->assertNoMissing($rootLevelIncludePatterns, $found);
        }

        return $found;
    }

    /**
     * @param RootPackageInterface $root
     * @param Factory $assetFactory
     * @return Asset|null
     */
    private function attemptFactoryRootPackageAsset(
        RootPackageInterface $root,
        Factory $assetFactory
    ): ?Asset {

        if (!$this->rootPackageConfig->isRunnable()) {
            return null;
        }

        $rootPackage = $assetFactory->attemptFactory(
            $root,
            $this->rootPackageConfig,
            Defaults::empty()
        );
        if ($rootPackage && $rootPackage->isValid()) {
            return $rootPackage;
        }

        return null;
    }

    /**
     * @return array{array<string, Config>, array<string>}
     * @throws \Exception
     */
    private function extractRootLevelPackagesData(): array
    {
        $packages = [];
        $exclude = [];

        foreach ($this->packagesData as $pattern => $packageData) {
            $config = $this->factoryRootLevelPackageConfig((string)$pattern, $packageData);
            if (!$config) {
                continue;
            }

            /** @var string $pattern */

            if ($config->isDisabled()) {
                $exclude[] = $pattern;
                continue;
            }

            $packages[$pattern] = $config;
        }

        return [$packages, $exclude];
    }

    /**
     * @param string $pattern
     * @param mixed $packageData
     * @return Config|null
     */
    private function factoryRootLevelPackageConfig(string $pattern, $packageData): ?Config
    {
        if (!$pattern) {
            if ($this->stopOnFailure) {
                throw new \Exception('Invalid packages settings.');
            }

            return null;
        }

        $rootEnv = $this->rootPackageConfig->defaultEnv();
        $config = Config::forAssetConfigInRoot($packageData, $this->modeResolver, $rootEnv);
        if (!$config->isValid()) {
            if ($this->stopOnFailure) {
                throw new \Exception("Package setting for '{$pattern}' is not valid.");
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
     * @param Asset|null $asset
     * @param string $name
     * @param bool $requiredExplicitlyByRoot
     * @return bool
     */
    private function assertValidAsset(
        ?Asset $asset,
        string $name,
        bool $requiredExplicitlyByRoot
    ): bool {

        $valid = $asset
            && $asset->isValid()
            && file_exists(($asset->path() ?? '.') . '/package.json');

        if (!$valid && $requiredExplicitlyByRoot && $this->stopOnFailure) {
            throw new \Exception("Could not find valid configuration for '{$name}'.");
        }

        return $valid;
    }

    /**
     * @param list<string> $rootLevelIncludePatterns
     * @param array<string, Asset> $found
     * @return void
     */
    private function assertNoMissing(array $rootLevelIncludePatterns, array $found): void
    {
        $missing = [];
        foreach ($rootLevelIncludePatterns as $rootLevelIncludePattern) {
            if (
                (stripos($rootLevelIncludePattern, '*') === false)
                && !array_key_exists($rootLevelIncludePattern, $found)
            ) {
                $missing[] = $rootLevelIncludePattern;
            }
        }

        if (!$missing) {
            return;
        }

        $oneMissing = count($missing) === 1;
        throw new \Exception(
            sprintf(
                'Package%s "%s" %s asset compiler config in root package but %s not installed.',
                $oneMissing ? '' : 's',
                implode('", "', $missing),
                $oneMissing ? 'has' : 'have',
                $oneMissing ? 'is' : 'are'
            )
        );
    }
}
