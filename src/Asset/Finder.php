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
use Inpsyde\AssetsCompiler\Util\EnvResolver;

class Finder
{

    /**
     * @var array
     */
    private $packagesData;

    /**
     * @var EnvResolver
     */
    private $envResolver;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Defaults<Config|null|>
     */
    private $defaults;

    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var bool
     */
    private $stopOnFailure;

    /**
     * @param array $packageData
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param Defaults $defaults
     * @param string $rootDir
     * @param bool $stopOnFailure
     * @return Finder
     */
    public static function new(
        array $packageData,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        Defaults $defaults,
        string $rootDir,
        bool $stopOnFailure
    ): Finder {

        return new self(
            $packageData,
            $envResolver,
            $filesystem,
            $defaults,
            $rootDir,
            $stopOnFailure
        );
    }

    /**
     * @param array $packageData
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param Defaults $defaults
     * @param string $rootDir
     * @param bool $stopOnFailure
     */
    private function __construct(
        array $packageData,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        Defaults $defaults,
        string $rootDir,
        bool $stopOnFailure
    ) {

        $this->packagesData = $envResolver->removeEnvConfig($packageData);
        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
        $this->defaults = $defaults;
        $this->rootDir = $rootDir;
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

        [$rootLevelIncludePackagesConfig, $excludeNames] = $this->extractRootLevelPackagesData();

        $found = [];

        $rootAsset = $this->attemptFactoryRootPackageAsset($root, $assetsFactory);
        $rootAsset and $found[$root->getName()] = $rootAsset;

        if (!$rootLevelIncludePackagesConfig && !$autoDiscover) {
            return $found;
        }

        $rootLevelIncludePatterns = array_keys($rootLevelIncludePackagesConfig);
        $packages = $repository->getPackages();

        foreach ($packages as $package) {
            $name = $package->getName();
            if (
                $package === $root
                || isset($found[$name])
                || $this->nameMatches($name, ...$excludeNames)[0]
            ) {
                continue;
            }

            [, $rootLevelPackagePattern] = $this->nameMatches($name, ...$rootLevelIncludePatterns);

            $rootLevelPackageConfig = $rootLevelPackagePattern
                ? ($rootLevelIncludePackagesConfig[$rootLevelPackagePattern] ?? null)
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

        $packageConfig = Config::forComposerPackage(
            $root,
            $this->rootDir,
            $this->envResolver,
            $this->filesystem
        );

        if (!$packageConfig->isRunnable()) {
            return null;
        }

        $rootPackage = $assetFactory->attemptFactory($root, $packageConfig, Defaults::empty());
        if ($rootPackage && $rootPackage->isValid()) {
            return $rootPackage;
        }

        return null;
    }

    /**
     * @return array{array<string, Config>, array<string>}
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

        $config = Config::forAssetConfigInRoot($packageData, $this->envResolver);
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
     * @param Asset $asset
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
