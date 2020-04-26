<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

class RootConfig
{
    public const AUTO_RUN = 'auto-run';
    public const COMMANDS = 'commands';
    public const DEFAULTS = 'defaults';
    public const PACKAGES = 'packages';
    public const STOP_ON_FAILURE = 'stop-on-failure';
    public const WIPE_NODE_MODULES = 'wipe-node-modules';
    public const AUTO_DISCOVER = 'auto-discover';
    public const MAX_PROCESSES = 'max-processes';
    public const PROCESSES_POLL = 'processes-poll';

    private const WIPE_FORCE = 'force';

    /**
     * @var array
     */
    private $raw;

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
    private $cache = [];

    /**
     * @var \Inpsyde\AssetsCompiler\PackageConfig
     */
    private $rootPackageConfig;

    /**
     * @param RootPackageInterface $package
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param Io $io
     */
    public function __construct(
        RootPackageInterface $package,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        Io $io
    ) {

        $data = $package->getExtra()[PackageConfig::EXTRA_KEY] ?? null;
        $this->raw = is_array($data) ? $data : [];
        $this->rootPackageConfig = PackageConfig::forComposerPackage($package, $envResolver);
        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
        $this->io = $io;
    }

    /**
     * @return EnvResolver
     */
    public function envResolver(): EnvResolver
    {
        return $this->envResolver;
    }

    /**
     * @return Filesystem
     */
    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /**
     * @return PackageFinder
     */
    public function packagesFinder(): PackageFinder
    {
        /** @var PackageFinder|null $cached */
        $cached = $this->cache[PackageFinder::class] ?? null;
        if ($cached) {
            return $cached;
        }

        $envResolver = $this->envResolver();
        $rawPackagesData = $this->raw[self::PACKAGES] ?? [];
        $packageData = is_array($rawPackagesData)
            ? $packageData = $envResolver->removeEnv($rawPackagesData)
            : [];

        $this->cache[PackageFinder::class] = new PackageFinder(
            $packageData,
            $envResolver,
            $this->defaults(),
            $this->stopOnFailure()
        );

        return $this->cache[PackageFinder::class];
    }

    /**
     * @return bool
     */
    public function autoDiscover(): bool
    {
        $config = $this->raw[self::AUTO_DISCOVER] ?? true;

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return bool
     */
    public function autoRun(): bool
    {
        $config = $this->resolveByEnv(self::AUTO_RUN, false, true);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param string $workingDir
     * @return Commands
     */
    public function commands(string $workingDir, ?ProcessExecutor $executor = null): Commands
    {
        /** @var Commands|null $cached */
        $cached = $this->cache[Commands::class] ?? null;
        if ($cached) {
            return $cached;
        }

        $config = $this->resolveByEnv(self::COMMANDS, true, null);
        $defaultEnv = $this->rootPackageConfig->defaultEnv();

        if (!$config || (!is_string($config) && !is_array($config))) {
            $executor or $executor = new ProcessExecutor();
            $commands = Commands::discover($executor, $workingDir, $defaultEnv);
            $this->cache[Commands::class] = $commands;

            return $commands;
        }

        if (is_string($config)) {
            $commands = Commands::fromDefault($config, $defaultEnv);
            if (!$commands->isValid()) {
                $this->io->writeError("'{$config}' is not valid, trying to auto-discover.");
                $commands = Commands::discover($executor ?? new ProcessExecutor(), $workingDir);
                $this->cache[Commands::class] = $commands;

                return $commands;
            }

            $this->cache[Commands::class] = $commands;

            return $commands;
        }

        $commands = new Commands($config, $defaultEnv);
        $this->cache[Commands::class] = $commands;

        return $commands;
    }

    /**
     * @return Defaults
     */
    public function defaults(): Defaults
    {
        if (array_key_exists(__METHOD__, $this->cache)) {
            /** @var Defaults $cached */
            $cached = $this->cache[__METHOD__];

            return $cached;
        }

        $config = $this->resolveByEnv(self::DEFAULTS, true, null);
        if (!$config || !is_array($config)) {
            $this->cache[__METHOD__] = Defaults::empty();

            return $this->cache[__METHOD__];
        }

        $defaults = Defaults::new(PackageConfig::forRawPackageData($config, $this->envResolver));

        $this->cache[__METHOD__] = $defaults;

        return $defaults;
    }

    /**
     * @return bool
     */
    public function stopOnFailure(): bool
    {
        $config = $this->resolveByEnv(self::STOP_ON_FAILURE, false, true);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return int
     */
    public function maxProcesses(): int
    {
        $config = $this->resolveByEnv(self::MAX_PROCESSES, false, 4);
        $maxProcesses = is_numeric($config) ? (int)$config : 4;
        ($maxProcesses < 1) and $maxProcesses = 1;

        return $maxProcesses;
    }

    /**
     * @return int
     */
    public function processesPoll(): int
    {
        $config = $this->resolveByEnv(self::PROCESSES_POLL, false, 100000);
        $poll = is_numeric($config) ? (int)$config : 100000;
        ($poll <= 10000) and $poll = 100000;

        return $poll;
    }

    /**
     * @param string $packageFolder
     * @return bool
     */
    public function wipeAllowed(string $packageFolder): bool
    {
        if (
            $this->filesystem->isSymlinkedDirectory($packageFolder)
            || $this->filesystem->isJunction($packageFolder)
        ) {
            return false;
        }

        $config = $this->resolveByEnv(self::WIPE_NODE_MODULES, false, true);
        if ($config === self::WIPE_FORCE) {
            return true;
        }

        if (!filter_var($config, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // This is called when the plugin starts process the package.
        // If `node_modules` dir exists, it means that was *not* created by the plugin itself,
        // but existed before, so we don't deleted it.

        return !is_dir("{$packageFolder}/node_modules");
    }

    /**
     * @param string $key
     * @param bool $allowedArray
     * @param $default
     * @return array|mixed
     *
     * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
     * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MissingReturnType
     */
    private function resolveByEnv(string $key, bool $allowedArray, $default)
    {
        // phpcs:enable Inpsyde.CodeQuality.ReturnTypeDeclaration
        // phpcs:enable Inpsyde.CodeQuality.ArgumentTypeDeclaration

        $config = $this->raw[$key] ?? null;
        if ($config === null) {
            return $default;
        }

        if (!is_array($config)) {
            return $config;
        }

        $byEnv = $this->envResolver->resolve($config);
        if ($byEnv === null) {
            return $allowedArray ? $this->envResolver->removeEnv($config) : $default;
        }

        if (is_array($byEnv) && !$allowedArray) {
            return $default;
        }

        return $byEnv;
    }
}
