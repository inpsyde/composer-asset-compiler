<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;

class PackageFactory
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
     */
    public function __construct(
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
     * @param \Composer\Package\PackageInterface $package
     * @param \Inpsyde\AssetsCompiler\PackageConfig|null $rootLevelPackageConfig
     * @param \Inpsyde\AssetsCompiler\Defaults $defaults
     * @return \Inpsyde\AssetsCompiler\Package|null
     */
    public function attemptFactory(
        PackageInterface $package,
        ?PackageConfig $rootLevelPackageConfig,
        Defaults $defaults
    ): ?Package {

        $config = $rootLevelPackageConfig;

        if ($config && $config->isForcedDefault() && !$defaults->isValid()) {
            return null;
        }

        if (!$rootLevelPackageConfig || $rootLevelPackageConfig->usePackageLevelOrDefault()) {
            $packageLevelConfig = PackageConfig::forComposerPackage($package, $this->envResolver);

            // If no package-level and no root-level config there's nothing we can do.
            if (!$rootLevelPackageConfig && !$packageLevelConfig->isValid()) {
                return null;
            }

            $config = $packageLevelConfig;
        }

        $validConfig = $config && $config->isRunnable();

        // If we have no config and no default, no way we can create a valid package.
        if (!$validConfig && !$defaults->isValid()) {
            return null;
        }

        if (!$config || $config->isForcedDefault()) {
            /** @var PackageConfig $config */
            $config = $defaults->toConfig();
        }

        $name = (string)($package->getName() ?? '');

        $installPath = ($package instanceof RootPackageInterface)
            ? $this->rootDir
            : $this->installationManager->getInstallPath($package);

        $path = (string)$this->filesystem->normalizePath($installPath);

        return Package::new($name, $config, $path);
    }
}
