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
use Inpsyde\AssetsCompiler\Util\ModeResolver;

class Factory
{
    /**
     * @var ModeResolver
     */
    private $modeResolver;

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
     * @var array<string, string>
     */
    private $rootEnv;

    /**
     * @param ModeResolver $modeResolver
     * @param Filesystem $filesystem
     * @param InstallationManager $installationManager
     * @param string $rootDir
     * @param array<string, string> $rootEnv
     * @return Factory
     */
    public static function new(
        ModeResolver $modeResolver,
        Filesystem $filesystem,
        InstallationManager $installationManager,
        string $rootDir,
        array $rootEnv
    ): Factory {

        return new static($modeResolver, $filesystem, $installationManager, $rootDir, $rootEnv);
    }

    /**
     * @param ModeResolver $modeResolver
     * @param Filesystem $filesystem
     * @param InstallationManager $installationManager
     * @param string $rootDir
     * @param array<string, string> $rootEnv
     */
    final private function __construct(
        ModeResolver $modeResolver,
        Filesystem $filesystem,
        InstallationManager $installationManager,
        string $rootDir,
        array $rootEnv
    ) {

        $this->modeResolver = $modeResolver;
        $this->filesystem = $filesystem;
        $this->installationManager = $installationManager;
        $this->rootDir = $rootDir;
        $this->rootEnv = $rootEnv;
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

        [$proceed, $config, $defaultConfig, $packageOrDefaultAllowed] = $this->initConfig(
            $rootLevelPackageConfig,
            $defaults
        );
        if (!$proceed) {
            return null;
        }

        $isRoot = ($package instanceof RootPackageInterface);
        $path = $isRoot
            ? $this->rootDir
            : ($this->installationManager->getInstallPath($package) ?? '');

        if (!$config && (!$rootLevelPackageConfig || $packageOrDefaultAllowed)) {
            $packageLevelConfig = Config::forComposerPackage(
                $package,
                $path,
                $this->modeResolver,
                $this->filesystem,
                $isRoot ? [] : $this->rootEnv
            );
            $packageLevelConfig->isRunnable() and $config = $packageLevelConfig;
        }

        if (!$config && $defaultConfig) {
            $config = $defaultConfig;
        }

        if (!$config || !$config->isRunnable()) {
            return null;
        }

        return Asset::new(
            $package->getName(),
            $config,
            $this->filesystem->normalizePath($path),
            $package->getPrettyVersion(),
            $package->getSourceReference() ?: $package->getDistReference()
        );
    }

    /**
     * @param Config|null $rootLevelPackageConfig
     * @param Defaults $defaults
     * @return array{bool, Config|null, Config|null, bool}
     */
    private function initConfig(?Config $rootLevelPackageConfig, Defaults $defaults): array
    {
        $defaultForced = false;
        $packageOrDefaultAllowed = false;
        if ($rootLevelPackageConfig) {
            $defaultForced = $rootLevelPackageConfig->isForcedDefault();
            $packageOrDefaultAllowed = $rootLevelPackageConfig->usePackageLevelOrDefault();
        }

        $defaultConfig = (($defaultForced || $packageOrDefaultAllowed) && $defaults->isValid())
            ? $defaults->toConfig()
            : null;

        if ($defaultForced && !$defaultConfig) {
            return [false, null, null, false];
        }

        $rootLevelConfig = ($rootLevelPackageConfig && $rootLevelPackageConfig->isRunnable())
            ? $rootLevelPackageConfig
            : null;

        /** @var Config|null $config */
        $config = null;
        if ($defaultForced) {
            $config = $defaultConfig;
        } elseif ($rootLevelConfig) {
            $config = $rootLevelConfig;
        }

        return [true, $config, $defaultConfig, $packageOrDefaultAllowed];
    }
}
