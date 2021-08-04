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
use Inpsyde\AssetsCompiler\Util\EnvResolver;

final class RootConfig
{
    public const AUTO_RUN = 'auto-run';
    public const DEFAULTS = 'defaults';
    public const ISOLATED_CACHE = 'isolated-cache';
    public const PACKAGES = 'packages';
    public const STOP_ON_FAILURE = 'stop-on-failure';
    public const WIPE_NODE_MODULES = 'wipe-node-modules';
    public const AUTO_DISCOVER = 'auto-discover';
    public const MAX_PROCESSES = 'max-processes';
    public const PROCESSES_POLL = 'processes-poll';

    private const WIPE_FORCE = 'force';

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
     * @var EnvResolver
     */
    private $envResolver;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param string $name
     * @param string $path
     * @param array $data
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @return RootConfig
     */
    public static function new(
        string $name,
        string $path,
        array $data,
        EnvResolver $envResolver,
        Filesystem $filesystem
    ): RootConfig {

        return new static($name, $path, $data, $envResolver, $filesystem);
    }

    /**
     * @param string $name
     * @param string $path
     * @param array $data
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     */
    private function __construct(
        string $name,
        string $path,
        array $data,
        EnvResolver $envResolver,
        Filesystem $filesystem
    ) {

        $this->name = $name;
        $this->path = rtrim($path, '/');
        $this->raw = $data;
        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
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
        $data = $this->resolveByEnv(self::PACKAGES, true, []);

        return is_array($data) ? $data : [];
    }

    /**
     * @return bool
     */
    public function autoDiscover(): bool
    {
        $config = $this->resolveByEnv(self::AUTO_DISCOVER, false, true);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return bool
     */
    public function autoRun(): bool
    {
        $config = $this->resolveByEnv(self::AUTO_RUN, false, false);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return bool
     */
    public function isolatedCache(): bool
    {
        $isolated = $this->resolveByEnv(self::ISOLATED_CACHE, false, false);

        return (bool)filter_var($isolated, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return Config|null
     */
    public function defaults(): ?Config
    {
        $data = $this->resolveByEnv(self::DEFAULTS, true, null);
        $config = Config::forAssetConfigInRoot($data, $this->envResolver);

        return $config->isRunnable() ? $config : null;
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
    public function isWipeAllowedFor(string $packageFolder): bool
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
        // but existed before, so we don't delete it.

        return !is_dir("{$packageFolder}/node_modules");
    }

    /**
     * @param string $key
     * @param bool $allowedArray
     * @param mixed $default
     * @return mixed
     */
    private function resolveByEnv(string $key, bool $allowedArray, $default)
    {
        $config = $this->raw[$key] ?? null;
        if ($config === null) {
            return $default;
        }

        if (!is_array($config)) {
            return $config;
        }

        $byEnv = $this->envResolver->resolveConfig($config);
        if ($byEnv === null) {
            return $allowedArray ? $this->envResolver->removeEnvConfig($config) : $default;
        }

        if (is_array($byEnv) && !$allowedArray) {
            return $default;
        }

        return $byEnv;
    }
}
