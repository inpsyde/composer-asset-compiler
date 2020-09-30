<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Util\EnvResolver;

final class RootConfig
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

    public const CONFIG_FILE = 'assets-compiler.json';

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
     * @var Config
     */
    private $rootPackageConfig;

    /**
     * @param RootPackageInterface $package
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param string $rootDir
     * @return RootConfig
     */
    public static function new(
        RootPackageInterface $package,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        string $rootDir
    ): RootConfig {

        return new static($package, $envResolver, $filesystem, $rootDir);
    }

    /**
     * @param RootPackageInterface $package
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     */
    private function __construct(
        RootPackageInterface $package,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        string $rootDir
    ) {

        $configFile = "{$rootDir}/" . self::CONFIG_FILE;
        $data = file_exists($configFile)
            ? JsonFile::parseJson(file_get_contents($configFile))
            : $package->getExtra()[Config::EXTRA_KEY] ?? null;

        $this->raw = is_array($data) ? $data : [];
        $this->rootPackageConfig = Config::forComposerPackage($package, $envResolver, $configFile);
        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
    }

    /**
     * @return array
     */
    public function packagesData(): array
    {
        $data = $this->raw[self::PACKAGES] ?? null;

        return is_array($data) ? $data : [];
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
        $config = $this->resolveByEnv(self::AUTO_RUN, false, false);

        return (bool)filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{string|array, bool}|array{null, null}
     */
    public function commands(): array
    {
        $config = $this->resolveByEnv(self::COMMANDS, true, null);
        $isDefaults = is_string($config);
        if (!$config || !$isDefaults && !is_array($config)) {
            return [null, null];
        }

        /** @var string|array $config */

        return [$config, $isDefaults];
    }

    /**
     * @return array|null
     */
    public function defaults(): ?array
    {
        $config = $this->resolveByEnv(self::DEFAULTS, true, null);
        if (!$config || !is_array($config)) {
            return null;
        }

        return $config;
    }

    /**
     * @return array
     */
    public function defaultEnv(): array
    {
        return $this->rootPackageConfig->defaultEnv();
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
        // but existed before, so we don't deleted it.

        return !is_dir("{$packageFolder}/node_modules");
    }

    /**
     * @param string $key
     * @param bool $allowedArray
     * @param mixed $default
     * @return mixed
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MissingReturnType
     * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
     * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
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
