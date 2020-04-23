<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

class Config
{
    public const AUTO_RUN = 'auto-run';
    public const COMMANDS = 'commands';
    public const DEFAULTS = 'defaults';
    public const PACKAGES = 'packages';
    public const STOP_ON_FAILURE = 'stop-on-failure';
    public const WIPE_NODE_MODULES = 'wipe-node-modules';
    public const AUTO_DISCOVER = 'auto-discover';
    public const DEF_ENV = 'default-env';
    public const MAX_PROCESSES = 'max-processes';
    public const PROCESSES_POLL = 'processes-poll';

    private const EXTRA_KEY = 'composer-asset-compiler';
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
     * @param PackageInterface $package
     * @return array|null
     */
    public static function configFromPackage(PackageInterface $package): ?array
    {
        $extra = $package->getExtra()[self::EXTRA_KEY] ?? null;

        return is_array($extra) ? $extra : null;
    }

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

        $this->raw = static::configFromPackage($package) ?? [];
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
        $config = $this->raw[self::AUTO_RUN] ?? true;

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

        $config = $this->raw[self::COMMANDS] ?? null;
        if (is_array($config)) {
            $envConfig = $this->envResolver->resolve($config);
            if ($envConfig && (is_string($envConfig) || is_array($envConfig))) {
                $config = $envConfig;
            }
        }

        if (!$config || (!is_string($config) && !is_array($config))) {
            $executor or $executor = new ProcessExecutor();
            $commands = Commands::discover($executor, $workingDir, $this->defaultEnv());
            $this->cache[Commands::class] = $commands;

            return $commands;
        }

        if (is_string($config)) {
            $commands = Commands::fromDefault($config, $this->defaultEnv());
            if (!$commands->isValid()) {
                $this->io->writeError("'{$config}' is not valid, trying to auto-discover.");
                $commands = Commands::discover($executor ?? new ProcessExecutor(), $workingDir);
                $this->cache[Commands::class] = $commands;

                return $commands;
            }

            $this->cache[Commands::class] = $commands;

            return $commands;
        }

        $commands = new Commands($config, $this->defaultEnv());
        $this->cache[Commands::class] = $commands;

        return $commands;
    }

    /**
     * @return Package|null
     */
    public function defaults(): ?Package
    {
        if (array_key_exists(__METHOD__, $this->cache)) {
            /** @var Package|null $cached */
            $cached = $this->cache[__METHOD__];

            return $cached;
        }

        $config = $this->raw[self::DEFAULTS] ?? null;
        if (!is_array($config)) {
            $this->cache[__METHOD__] = null;

            return null;
        }

        $byEnv = $this->envResolver->resolve($config);
        ($byEnv && is_array($byEnv)) and $config = $byEnv;

        $defaults = Package::defaults($config);

        if (!$defaults->isValid()) {
            $this->cache[__METHOD__] = null;

            return null;
        }

        $this->cache[__METHOD__] = $defaults;

        return $defaults;
    }

    /**
     * @return bool
     */
    public function stopOnFailure(): bool
    {
        if (array_key_exists(__METHOD__, $this->cache)) {
            return (bool)$this->cache[__METHOD__];
        }

        $config = $this->raw[self::STOP_ON_FAILURE] ?? true;
        if (is_array($config)) {
            $byEnv = $this->envResolver->resolve($config);
            $config = $byEnv === null ? true : $byEnv;
        }

        $stop = (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
        $this->cache[__METHOD__] = $stop;

        return $stop;
    }

    /**
     * @return int
     */
    public function maxProcesses(): int
    {
        if (array_key_exists(__METHOD__, $this->cache)) {
            return (int)$this->cache[__METHOD__];
        }

        $config = $this->raw[self::MAX_PROCESSES] ?? null;
        if (is_array($config)) {
            $byEnv = $this->envResolver->resolve($config);
            $config = is_numeric($byEnv) ? (int)$byEnv : null;
        }

        $maxProcesses = is_numeric($config) ? (int)$config : 4;
        ($maxProcesses < 1) and $maxProcesses = 1;

        $this->cache[__METHOD__] = $maxProcesses;

        return $maxProcesses;
    }

    /**
     * @return int
     */
    public function processesPoll(): int
    {
        if (array_key_exists(__METHOD__, $this->cache)) {
            return (int)$this->cache[__METHOD__];
        }

        $config = $this->raw[self::PROCESSES_POLL] ?? null;
        if (is_array($config)) {
            $byEnv = $this->envResolver->resolve($config);
            $config = is_numeric($byEnv) ? (int)$byEnv : null;
        }

        $poll = is_numeric($config) ? (int)$config : 100000;
        ($poll <= 10000) and $poll = 100000;

        $this->cache[__METHOD__] = $poll;

        return $poll;
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

        $packageData = $this->raw[self::PACKAGES] ?? [];
        $packageData = is_array($packageData) ? $packageData : [];

        $finder = new PackageFinder($packageData, $this->defaults(), $this->stopOnFailure());
        $this->cache[PackageFinder::class] = $finder;

        return $finder;
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

        $config = $this->raw[self::WIPE_NODE_MODULES] ?? true;

        if (is_array($config)) {
            $byEnv = $this->envResolver->resolve($config);
            $config = $byEnv === null ? true : $byEnv;
        }

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
     * @return array<string, string>
     */
    public function defaultEnv(): array
    {
        /** @var array<string, string>|null $cached */
        $cached = $this->cache[self::DEF_ENV] ?? null;
        if (is_array($cached)) {
            return $cached;
        }

        $config = $this->raw[self::DEF_ENV] ?? null;
        if (!is_array($config) && !$config instanceof \stdClass) {
            return [];
        }

        $sanitized = EnvResolver::sanitizeEnvVars((array)$config);
        $this->cache[self::DEF_ENV] = $sanitized;

        return $sanitized;
    }
}
