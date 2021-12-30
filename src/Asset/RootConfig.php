<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Util\Env;
use Inpsyde\AssetsCompiler\Util\ModeResolver;

final class RootConfig
{
    public const AUTO_RUN = 'auto-run';
    public const DEFAULTS = 'defaults';
    public const PACKAGES = 'packages';
    public const STOP_ON_FAILURE = 'stop-on-failure';
    public const WIPE_NODE_MODULES = 'wipe-node-modules';
    public const AUTO_DISCOVER = 'auto-discover';
    public const MAX_PROCESSES = 'max-processes';
    public const PROCESSES_POLL = 'processes-poll';
    public const TIMEOUT_INCR = 'timeout-increment';

    private const WIPE_FORCE = 'force';
    private const BY_ENV = [
        self::STOP_ON_FAILURE => 'COMPOSER_ASSET_COMPILER_STOP_ON_FAILURE',
        self::WIPE_NODE_MODULES => 'COMPOSER_ASSET_COMPILER_WIPE_NODE_MODULES',
        self::AUTO_DISCOVER => 'COMPOSER_ASSET_COMPILER_AUTO_DISCOVER',
        self::MAX_PROCESSES => 'COMPOSER_ASSET_COMPILER_MAX_PROCESSES',
        self::PROCESSES_POLL => 'COMPOSER_ASSET_COMPILER_PROCESSES_POLL',
        self::TIMEOUT_INCR => 'COMPOSER_ASSET_COMPILER_TIMEOUT_INCR',
    ];

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $path;

    /**
     * @var array
     */
    private $raw;

    /**
     * @var ModeResolver
     */
    private $modeResolver;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var array<string, string>
     */
    private $defaultEnv;

    /**
     * @param string $name
     * @param string $path
     * @param array $data
     * @param ModeResolver $modeResolver
     * @param Filesystem $filesystem
     * @param array<string, string> $defaultEnv
     * @return RootConfig
     */
    public static function new(
        string $name,
        string $path,
        array $data,
        ModeResolver $modeResolver,
        Filesystem $filesystem,
        array $defaultEnv = []
    ): RootConfig {

        return new static($name, $path, $data, $modeResolver, $filesystem, $defaultEnv);
    }

    /**
     * @param string $name
     * @param string $path
     * @param array $data
     * @param ModeResolver $modeResolver
     * @param Filesystem $filesystem
     * @param array<string, string> $defaultEnv
     */
    private function __construct(
        string $name,
        string $path,
        array $data,
        ModeResolver $modeResolver,
        Filesystem $filesystem,
        array $defaultEnv
    ) {

        $this->name = $name;
        $this->path = rtrim($path, '/');
        $this->raw = $data;
        $this->modeResolver = $modeResolver;
        $this->filesystem = $filesystem;
        $this->defaultEnv = $defaultEnv;
    }

    /**
     * @return Config
     */
    public function config(): Config
    {
        return Config::new($this->raw, $this->modeResolver, $this->defaultEnv);
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array
     */
    public function packagesData(): array
    {
        $data = $this->resolveByMode(self::PACKAGES, true, []);

        return is_array($data) ? $data : [];
    }

    /**
     * @return bool
     */
    public function autoDiscover(): bool
    {
        $config = $this->resolveByMode(self::AUTO_DISCOVER, false, true);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return bool
     */
    public function autoRun(): bool
    {
        $config = $this->resolveByMode(self::AUTO_RUN, false, false);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return Config|null
     */
    public function defaults(): ?Config
    {
        $data = $this->resolveByMode(self::DEFAULTS, true, null);
        $config = Config::forAssetConfigInRoot($data, $this->modeResolver, $this->defaultEnv);

        return $config->isRunnable() ? $config : null;
    }

    /**
     * @return bool
     */
    public function stopOnFailure(): bool
    {
        $config = $this->resolveByMode(self::STOP_ON_FAILURE, false, true);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return int
     */
    public function maxProcesses(): int
    {
        $config = $this->resolveByMode(self::MAX_PROCESSES, false, 4);
        $maxProcesses = is_numeric($config) ? (int)$config : 4;
        ($maxProcesses < 1) and $maxProcesses = 1;

        return $maxProcesses;
    }

    /**
     * @return int
     */
    public function processesPoll(): int
    {
        $config = $this->resolveByMode(self::PROCESSES_POLL, false, 100000);
        $poll = is_numeric($config) ? (int)$config : 100000;
        ($poll <= 10000) and $poll = 100000;

        return $poll;
    }

    /**
     * @return int
     */
    public function timeoutIncrement(): int
    {
        $config = $this->resolveByMode(self::TIMEOUT_INCR, false, null);

        $incr = is_numeric($config) ? (int)$config : 300;

        return min(max(30, $incr), 3600);
    }

    /**
     * @param string $packageFolder
     * @return bool
     */
    public function isWipeAllowedFor(string $packageFolder): bool
    {
        if (
            $this->filesystem->isSymlinkedDirectory($packageFolder)
            || $this->filesystem->isJunction($packageFolder)
        ) {
            return false;
        }

        $config = $this->resolveByMode(self::WIPE_NODE_MODULES, false, false);
        if ($config === self::WIPE_FORCE) {
            return true;
        }

        if (!filter_var($config, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // This is called when the plugin starts process the package.
        // If `node_modules` dir exists, it means that was *not* created by the plugin itself,
        // but existed before, so we don't delete it.

        return !is_dir("{$packageFolder}/node_modules");
    }

    /**
     * @param string $key
     * @param bool $allowedArray
     * @param mixed $default
     * @return mixed
     */
    private function resolveByMode(string $key, bool $allowedArray, $default)
    {
        $config = $this->raw[$key] ?? null;
        if (($config === null) && (self::BY_ENV[$key] ?? null)) {
            $config = Env::readEnv(self::BY_ENV[$key], $this->defaultEnv);
        }

        if ($config === null) {
            return $default;
        }

        if (!is_array($config)) {
            return $config;
        }

        $byMode = $this->modeResolver->resolveConfig($config);
        if ($byMode === null) {
            return $allowedArray ? $this->modeResolver->removeModeConfig($config) : $default;
        }

        if (is_array($byMode) && !$allowedArray) {
            return $default;
        }

        return $byMode;
    }
}
