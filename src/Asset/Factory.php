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

        $defaultForced = $rootLevelPackageConfig && $rootLevelPackageConfig->isForcedDefault();
        $defaultConfig = $defaults->isValid() ? $defaults->toConfig() : null;
        if ($defaultForced && !$defaultConfig->isRunnable()) {
            return null;
        }

        /** @var Config|null $config */
        $config = null;
        if ($defaultForced) {
            $config = $defaultConfig;
        } elseif ($rootLevelPackageConfig && $rootLevelPackageConfig->isRunnable()) {
            $config = $rootLevelPackageConfig;
        }

        /** @var Config $config */

        $installPath = ($package instanceof RootPackageInterface)
            ? $this->rootDir
            : $this->installationManager->getInstallPath($package);

        /** @var Config|null $config */
        if (
            !$config
            && (!$rootLevelPackageConfig || $rootLevelPackageConfig->usePackageLevelOrDefault())
        ) {
            $packageLevelConfig = Config::forComposerPackage(
                $package,
                $this->envResolver,
                "{$installPath}/" . RootConfig::CONFIG_FILE
            );

            $config = ($packageLevelConfig && $packageLevelConfig->isRunnable())
                ? $packageLevelConfig
                : $defaultConfig;

            // If no root-level config and no package-level config there's nothing we can do.
            if (!$config) {
                return null;
            }
        }

        if (!$config || !$config->isRunnable()) {
            return null;
        }

        $path = (string)$this->filesystem->normalizePath($installPath);

        return Asset::new($package->getName(), $config, $path, $package->getPrettyVersion());
    }
}
