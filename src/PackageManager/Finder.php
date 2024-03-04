<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PackageManager;

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\Util\Io;

class Finder
{
    private PackageManager|null $discovered = null;

    /**
     * @param ProcessExecutor $executor
     * @param ModeResolver $modeResolver
     * @param Filesystem $filesystem ,
     * @param Io $io
     * @param array $defaultEnv
     * @return Finder
     */
    public static function new(
        ProcessExecutor $executor,
        Filesystem $filesystem,
        Io $io,
        array $defaultEnv
    ): Finder {

        return new self($executor, $filesystem, $io, $defaultEnv);
    }

    /**
     * @param ProcessExecutor $executor
     * @param Filesystem $filesystem
     * @param Io $io
     * @param array $defaultEnv
     */
    private function __construct(
        private ProcessExecutor $executor,
        private Filesystem $filesystem,
        private Io $io,
        private array $defaultEnv
    ) {
    }

    /**
     * @param Config $config
     * @param string $name
     * @param string $path
     * @return PackageManager
     */
    public function findForConfig(Config $config, string $name, string $path): PackageManager
    {
        $path = rtrim($this->filesystem->normalizePath($path), '/');

        $manager = $config->packageManager();

        if ($manager && $this->checkIsValid($manager, $name, $path, true, true)) {
            return $manager->withDefaultEnv();
        }

        $manager = null;
        if (
            file_exists("{$path}/npm-shrinkwrap.json")
            || file_exists("{$path}/package-lock.json")
        ) {
            $manager = PackageManager::fromDefault(PackageManager::NPM);
        } elseif (file_exists("{$path}/yarn.lock")) {
            $manager = PackageManager::fromDefault(PackageManager::YARN);
        }

        if ($manager && $this->checkIsValid($manager, $name, $path, false, true)) {
            return $manager->withDefaultEnv();
        }

        $this->io->writeVerbose("Will use default package manager for {$name}.");

        $this->discovered = $this->discovered ?: PackageManager::discover($this->executor, $path);

        $manager = $this->discovered;
        if ($this->checkIsValid($manager, $name, $path, true, false)) {
            return $manager->withDefaultEnv();
        }

        $this->io->writeError(
            "Could not found a package manager in the system.",
            'Make sure either Yarn or npm are installed.'
        );

        throw new \Exception('Package manager not found.');
    }

    /**
     * @param Asset $asset
     * @return PackageManager
     */
    public function findForAsset(Asset $asset): PackageManager
    {
        $path = $asset->path() ?? '';
        $config = $asset->config();
        if (($path === '') || ($config === null)) {
            return PackageManager::new([]);
        }

        return $this->findForConfig($config, $asset->name(), $path);
    }

    /**
     * @param PackageManager $packageManager
     * @param string $name
     * @param string $dir
     * @param bool $checkValid
     * @param bool $checkExec
     * @return bool
     *
     * @psalm-assert-if-true PackageManager $packageManager
     */
    private function checkIsValid(
        PackageManager $packageManager,
        string $name,
        string $dir,
        bool $checkValid,
        bool $checkExec
    ): bool {

        $byConfig = $checkValid && $checkExec;
        $byLockFile = !$checkValid && $checkExec;

        $valid = !$checkValid || $packageManager->isValid();
        $error = $byConfig
            ? "Package manager configuration is invalid for '{$name}'."
            : null;
        if ($valid && $checkExec && !$packageManager->isExecutable($this->executor, $dir)) {
            $valid = false;
            $pmName = $packageManager->name();
            $error = $byLockFile
                ? "'{$name}' has a {$pmName}-specific lock file, "
                : "'{$name}' seems to require {$pmName} via configuration, ";
            $error .= "but that package manager is not available on the system.";
        }

        if (!$valid && ($error !== null)) {
            $this->io->writeVerboseError($error);
        }

        return $valid;
    }
}
