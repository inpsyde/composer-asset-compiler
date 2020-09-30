<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Util\EnvResolver;

class Factory
{
    /**
     * @var EnvResolver
     */
    private $envResolver;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var InstallationManager
     */
    private $installationManager;

    /**
     * @var string
     */
    private $rootDir;

    /**
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param InstallationManager $installationManager
     * @param string $rootDir
     * @return Factory
     */
    public static function new(
        EnvResolver $envResolver,
        Filesystem $filesystem,
        InstallationManager $installationManager,
        string $rootDir
    ): Factory {

        return new static($envResolver, $filesystem, $installationManager, $rootDir);
    }

    /**
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param InstallationManager $installationManager
     */
    final private function __construct(
        EnvResolver $envResolver,
        Filesystem $filesystem,
        InstallationManager $installationManager,
        string $rootDir
    ) {

        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
        $this->installationManager = $installationManager;
        $this->rootDir = $rootDir;
    }

    /**
     * @param PackageInterface $package
     * @param Config|null $rootLevelPackageConfig
     * @param Defaults $defaults
     * @return Asset|null
     */
    public function attemptFactory(
        PackageInterface $package,
        ?Config $rootLevelPackageConfig,
        Defaults $defaults
    ): ?Asset {

        $config = $rootLevelPackageConfig;

        if ($config && $config->isForcedDefault() && !$defaults->isValid()) {
            return null;
        }

        /** @var Config $config */

        $installPath = ($package instanceof RootPackageInterface)
            ? $this->rootDir
            : $this->installationManager->getInstallPath($package);

        /** @var Config|null $config */
        if (!$config || $config->usePackageLevelOrDefault()) {
            $packageLevelConfig = Config::forComposerPackage(
                $package,
                $this->envResolver,
                "{$installPath}/" . RootConfig::CONFIG_FILE
            );

            // If no root-level config and no package-level config there's nothing we can do.
            if (!$rootLevelPackageConfig && !$packageLevelConfig->isValid()) {
                return null;
            }

            $config = $packageLevelConfig;
        }

        /** @var Config|null $config */
        $validConfig = $config && $config->isRunnable();

        // If we have no config and no default, no way we can create a valid package.
        if (!$validConfig && !$defaults->isValid()) {
            return null;
        }

        /** @var Config|null $config */
        if (!$config || $config->isForcedDefault()) {
            $config = $defaults->toConfig();
        }

        $path = (string)$this->filesystem->normalizePath($installPath);

        return Asset::new($package->getName(), $config, $path, $package->getPrettyVersion());
    }
}
