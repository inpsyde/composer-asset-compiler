<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryInterface;

class PackageFinder
{
    private const FORCE_DEFAULTS = 'force-defaults';

    /**
     * @var array
     */
    private $packageData;

    /**
     * @var Package|null
     */
    private $defaults;

    /**
     * @var bool
     */
    private $stopOnFailure;

    /**
     * @param array $packageData
     * @param Package|null $defaults
     * @param bool $stopOnFailure
     */
    public function __construct(array $packageData, ?Package $defaults, bool $stopOnFailure)
    {
        $this->packageData = $packageData;
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
         * @var array<string,array|null> $include
         * @var array<int,string> $exclude
         */
        [$include, $exclude] = $this->prepareData();

        if (!$include && !$autoDiscover) {
            return [];
        }

        $found = [];

        /** @var PackageInterface[] $composerPackages */
        $composerPackages = $repository->getPackages();
        array_unshift($composerPackages, $root);

        foreach ($composerPackages as $composerPackage) {
            /** @var string $name */
            $name = $composerPackage->getName();
            if (isset($found[$name]) || $this->shouldExclude($name, ...$exclude)) {
                continue;
            }

            $config = $this->includeData($name, $include);

            if (($config === null && !$autoDiscover)) {
                continue;
            }

            // if package was included with `true` (config is `[]`), we allow look in package config
            $packageConfigAllowed = $autoDiscover || ($config === []);

            $package = $packageFactory->factory(
                $composerPackage,
                $config ?: null, // we pass either non-empty array or null
                $this->defaults,
                $packageConfigAllowed
            );

            if ($package->isDefault()
                || !file_exists($package->path() . '/package.json')
                || !$this->assertValidPackage($package, $root, $config)
            ) {
                continue;
            }

            $found[$name] = $package;
        }

        return $found;
    }

    /**
     * @return array
     */
    private function prepareData(): array
    {
        /** @var array<string, array|null> $include */
        $include = [];

        /** @var string[] $exclude */
        $exclude = [];

        $defaultsConfig = $this->defaults ? $this->defaults->toArray() : null;

        foreach ($this->packageData as $key => $packageData) {
            if (!$key || !is_string($key)) {
                continue;
            }

            if ($packageData === self::FORCE_DEFAULTS) {
                $this->assertDefaults();
                $defaultsConfig and $include[$key] = $defaultsConfig;
                continue;
            }

            $isArray = is_array($packageData);

            // No array, no bool: invalid
            if (!$isArray && !is_bool($packageData)) {
                continue;
            }

            // `$packageData` is `false`, let's add to exclude
            if (!$isArray && !$packageData) {
                $exclude[] = $key;
                continue;
            }

            // if `$packageData` is true, means "use package-level config", we set to null
            $include[$key] = $isArray ? $packageData : null;
        }

        return [$include, $exclude];
    }

    /**
     * @param string $name
     * @param string[] $exclude
     * @return bool
     */
    private function shouldExclude(string $name, string ...$exclude): bool
    {
        foreach ($exclude as $pattern) {
            if ($pattern === $name
                || fnmatch($pattern, $name, FNM_PATHNAME | FNM_PERIOD | FNM_CASEFOLD)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Possible return values:
     * - `null`: means "auto discover",
     * - empty array: is included with no config
     * - non-empty array: included with some config
     *
     * @param string $name
     * @param array $include
     * @return array|null
     */
    private function includeData(string $name, array $include): ?array
    {
        /**
         * @var string $pattern
         * @var array|null $data
         */
        foreach ($include as $pattern => $data) {
            if ($pattern === $name
                || fnmatch($pattern, $name, FNM_PATHNAME | FNM_PERIOD | FNM_CASEFOLD)
            ) {
                return $data ?? [];
            }
        }

        // Not in include, auto-discover required
        return null;
    }

    /**
     * @return void
     */
    private function assertDefaults(): void
    {
        if ($this->stopOnFailure && !$this->defaults) {
            throw new \Exception(
                sprintf(
                    '"%s" is used in %s settings, however %s configuration is missing.',
                    self::FORCE_DEFAULTS,
                    Config::PACKAGES,
                    Config::DEFAULTS
                )
            );
        }
    }

    /**
     * @param Package $package
     * @param RootPackageInterface $root
     * @param array|null $config
     * @return bool
     */
    private function assertValidPackage(
        Package $package,
        RootPackageInterface $root,
        ?array $config
    ): bool {

        if ($package->isValid()) {
            return true;
        }

        if ($this->stopOnFailure && ($config !== null || ($package !== $root))) {
            $name = $package->name();
            throw new \Exception("Could not find valid configuration for '{$name}'.");
        }

        return false;
    }
}
