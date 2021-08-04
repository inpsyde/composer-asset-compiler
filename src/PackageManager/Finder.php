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
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Util\Io;

class Finder
{
    /**
     * @var ProcessExecutor
     */
    private $executor;

    /**
     * @var EnvResolver
     */
    private $envResolver;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var array
     */
    private $defaultEnv;

    /**
     * @var PackageManager|null
     */
    private $discovered;

    /**
     * @param ProcessExecutor $executor
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem,
     * @param Io $io
     * @param array $defaultEnv
     * @return Finder
     */
    public static function new(
        ProcessExecutor $executor,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        Io $io,
        array $defaultEnv
    ): Finder {

        return new self($executor, $envResolver, $filesystem, $io, $defaultEnv);
    }

    /**
     * @param ProcessExecutor $executor
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem,
     * @param Io $io
     * @param array $defaultEnv
     */
    private function __construct(
        ProcessExecutor $executor,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        Io $io,
        array $defaultEnv
    ) {

        $this->executor = $executor;
        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
        $this->io = $io;
        $this->defaultEnv = $defaultEnv;
    }

    /**
     * @param Config $config
     * @param string $name
     * @param string $path
     * @return PackageManager
     * @throws \Exception
     */
    public function findForConfig(Config $config, string $name, string $path): PackageManager
    {
        $path = rtrim($this->filesystem->normalizePath($path), '/');

        $manager = $config->packageManager();

        if ($this->checkIsValid($manager, $name, $path, true, true)) {
            return $manager->withDefaultEnv($this->defaultEnv);
        }

        switch (true) {
            case file_exists("{$path}/package-lock.json"):
            case file_exists("{$path}/npm-shrinkwrap.json"):
                $manager = PackageManager::fromDefault(PackageManager::NPM);
                break;
            case file_exists("{$path}/yarn.lock"):
                $manager = PackageManager::fromDefault(PackageManager::YARN);
                break;
        }

        if ($manager && $this->checkIsValid($manager, $name, $path, false, true)) {
            return $manager->withDefaultEnv($this->defaultEnv);
        }

        $this->discovered = $this->discovered ?: PackageManager::discover($this->executor, $path);

        $manager = $this->discovered;
        if ($this->checkIsValid($manager, $name, $path, true, false)) {
            return $manager->withDefaultEnv($this->defaultEnv);
        }

        $this->io->writeError(
            " Could not found a package manager in the system.",
            ' Make sure either Yarn or npm are installed.'
        );

        throw new \Exception('Package manager not found.');
    }

    /**
     * @param Asset $asset
     * @return PackageManager
     */
    public function findForAsset(Asset $asset): PackageManager
    {
        $path = $asset->path();
        $config = $asset->config();
        if (!$path || !$config) {
            return PackageManager::new([], []);
        }

        return $this->findForConfig($config, $asset->name(), $path);
    }

    /**
     * @param PackageManager|null $packageManager
     * @param string $name
     * @param string $dir
     * @param bool $checkValid
     * @param bool $checkExec
     * @return bool
     *
     * @psalm-assert-if-true PackageManager $packageManager
     */
    private function checkIsValid(
        ?PackageManager $packageManager,
        string $name,
        string $dir,
        bool $checkValid,
        bool $checkExec
    ): bool {

        $valid = $packageManager && (!$checkValid || $packageManager->isValid());
        $errors = [" 'commands' configuration is invalid for '{$name}'."];
        if ($valid && $checkExec && !$packageManager->isExecutable($this->executor, $dir)) {
            $valid = false;
            $pmName = $packageManager->name();
            $error = $checkValid
                ? "'{$name}' seems to require {$pmName} via configuration"
                : "'{$name}' has lock file for {$pmName}";
            $errors = [" {$error}, but that package manager is not available on the system."];
            if ($checkValid) {
                $errors[] = ' Will now try to discover an executable package manager.';
            }
        }

        $valid or $this->io->writeVerboseError(...$errors);

        return $valid;
    }
}
