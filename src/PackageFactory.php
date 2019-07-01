<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
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
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param InstallationManager $installationManager
     */
    public function __construct(
        EnvResolver $envResolver,
        Filesystem $filesystem,
        InstallationManager $installationManager
    ) {

        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
        $this->installationManager = $installationManager;
    }

    /**
     * @param PackageInterface $package
     * @param array|null $config
     * @param Package|null $defaults
     * @param bool $packageConfigAllowed
     * @return Package
     */
    public function factory(
        PackageInterface $package,
        ?array $config,
        ?Package $defaults,
        bool $packageConfigAllowed
    ): Package {

        if ($config) {
            $configByEnv = $this->envResolver->resolve($config);
            ($configByEnv && is_array($configByEnv)) and $config = $configByEnv;
        }

        if (!$config && $packageConfigAllowed) {
            $packageConfig = Config::configFromPackage($package);
            if ($packageConfig) {
                $packageByEnv = $this->envResolver->resolve($packageConfig);
                ($packageByEnv && is_array($packageByEnv)) and $packageConfig = $packageByEnv;
            }

            $packageConfig and $config = $packageConfig;
        }

        if (!$config && $defaults) {
            $config = $defaults->toArray();
        }

        $installPath = $this->installationManager->getInstallPath($package);
        $path = $this->filesystem->normalizePath($installPath);
        $name = $package->getName();

        return new Package((string)$name, $config ?? [], (string)$path);
    }
}
