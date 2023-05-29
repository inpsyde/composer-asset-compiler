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
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\PackageManager;
use Inpsyde\AssetsCompiler\Util\Env;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\PreCompilation;

class Config
{
    public const EXTRA_KEY = 'composer-asset-compiler';
    public const DEF_ENV = 'default-env';
    public const DEPENDENCIES = 'dependencies';
    public const SCRIPT = 'script';
    public const PACKAGE_MANAGER = 'package-manager';
    public const PRE_COMPILED = 'pre-compiled';
    public const ISOLATED_CACHE = 'isolated-cache';
    public const SRC_PATHS = 'src-paths';
    public const INSTALL = 'install';
    public const UPDATE = 'update';
    public const NONE = 'none';

    private const CONFIG_FILE = 'assets-compiler.json';
    private const FORCE_DEFAULTS = '$force-defaults';
    private const BY_PACKAGE_OR_DEFAULTS = 'package-or-defaults';
    private const DISABLED = 'disabled';
    private const DEPENDENCIES_OPTIONS = [self::INSTALL, self::UPDATE, self::NONE];
    private const BASE_DATA = [
        self::DEPENDENCIES => self::NONE,
        self::SCRIPT => null,
        self::DEF_ENV => null,
        self::PRE_COMPILED => null,
        self::BY_PACKAGE_OR_DEFAULTS => false,
        self::FORCE_DEFAULTS => false,
        self::DISABLED => false,
        self::PACKAGE_MANAGER => null,
        self::ISOLATED_CACHE => false,
        self::SRC_PATHS => [],
    ];

    /**
     * @var bool
     */
    private $byPackage = false;

    /**
     * @var bool
     */
    private $byRootPackage = false;

    /**
     * @var ModeResolver
     */
    private $modeResolver;

    /**
     * @var array
     */
    private $raw;

    /**
     * @var RootConfig|null
     */
    private $rootConfig = null;

    /**
     * @var bool
     */
    private $dataWasParsed = false;

    /**
     * @var bool
     */
    private $valid = false;

    /**
     * @var array
     */
    private $data = self::BASE_DATA;

    /**
     * @var array<string, string>
     */
    private $rootEnv;

    /**
     * @param array $data
     * @param ModeResolver $modeResolver
     * @param array<string, string> $rootEnv
     * @return Config
     */
    public static function new(array $data, ModeResolver $modeResolver, array $rootEnv = []): Config
    {
        return new static($data, $modeResolver, $rootEnv);
    }

    /**
     * @param mixed $config
     * @param ModeResolver $modeResolver
     * @param array<string, string> $rootEnv = []
     * @return Config
     */
    public static function forAssetConfigInRoot(
        $config,
        ModeResolver $modeResolver,
        array $rootEnv = []
    ): Config {

        if (!is_array($config) && !is_bool($config) && !is_string($config)) {
            $config = [];
        }

        return new static(static::parseRaw($config, $modeResolver), $modeResolver, $rootEnv);
    }

    /**
     * @param PackageInterface $package
     * @param string $path
     * @param ModeResolver $modeResolver
     * @param Filesystem $filesystem
     * @param array<string, string> $rootEnv
     * @return Config
     */
    public static function forComposerPackage(
        PackageInterface $package,
        string $path,
        ModeResolver $modeResolver,
        Filesystem $filesystem,
        array $rootEnv = []
    ): Config {

        $configFile = null;
        if (is_string($path)) {
            $path = $filesystem->normalizePath($path);
            $configFile = "{$path}/" . self::CONFIG_FILE;
            file_exists($configFile) or $configFile = null;
        }

        $raw = $configFile
            ? JsonFile::parseJson(file_get_contents($configFile) ?: '')
            : $package->getExtra()[self::EXTRA_KEY] ?? [];

        $isRoot = $package instanceof RootPackageInterface;
        $isRoot and $rootEnv = [];

        $data = static::parseRaw($raw, $modeResolver);
        $instance = new static($data, $modeResolver, $rootEnv);
        $instance->byPackage = true;
        if ($isRoot) {
            $instance->byRootPackage = true;
            $name = $package->getName();
            $instance->rootConfig = RootConfig::new(
                $name,
                $path,
                $data,
                $modeResolver,
                $filesystem,
                $instance->defaultEnv()
            );
        }

        return $instance;
    }

    /**
     * @param mixed $raw
     * @param ModeResolver $modeResolver
     * @return array
     */
    private static function parseRaw($raw, ModeResolver $modeResolver): array
    {
        $config = $raw;

        $byMode = null;
        $noEnv = null;
        if (is_array($raw)) {
            $byMode = $modeResolver->resolveConfig($raw);
            $noEnv = $modeResolver->removeModeConfig($raw);
            $config = ($byMode === null) ? $noEnv : $byMode;
        }

        if (is_bool($config)) {
            $config = $config ? [self::BY_PACKAGE_OR_DEFAULTS => true] : [self::DISABLED => true];
        }

        switch (true) {
            case ($config === self::DISABLED):
            case ($config === self::FORCE_DEFAULTS):
            case ($config === self::BY_PACKAGE_OR_DEFAULTS):
                $config = [$config => true];
                break;
            case (is_string($config)):
                $config = [self::DEPENDENCIES => self::INSTALL, self::SCRIPT => $raw];
                break;
        }

        is_array($config) or $config = [];

        return ($byMode && $noEnv) ? array_merge($noEnv, $config) : $config;
    }

    /**
     * @param array $raw
     * @param ModeResolver $modeResolver
     * @param array<string, string> $rootEnv
     */
    final private function __construct(array $raw, ModeResolver $modeResolver, array $rootEnv = [])
    {
        $this->raw = $raw;
        $this->modeResolver = $modeResolver;
        $this->rootEnv = $rootEnv;
    }

    /**
     * @return RootConfig|null
     */
    public function rootConfig(): ?RootConfig
    {
        return $this->rootConfig;
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        $this->parseData();

        return $this->valid || $this->byRootPackage;
    }

    /**
     * @return bool
     */
    public function isByPackage(): bool
    {
        return $this->byPackage;
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        if ($this->isByPackage()) {
            return false;
        }

        $this->parseData();

        return (bool)$this->data[self::DISABLED];
    }

    /**
     * @return bool
     */
    public function usePackageLevelOrDefault(): bool
    {
        if ($this->isByPackage()) {
            return false;
        }

        $this->parseData();

        return (bool)$this->data[self::BY_PACKAGE_OR_DEFAULTS];
    }

    /**
     * @return bool
     */
    public function isForcedDefault(): bool
    {
        if ($this->isByPackage()) {
            return false;
        }

        $this->parseData();

        return (bool)$this->data[self::FORCE_DEFAULTS];
    }

    /**
     * @return bool
     */
    public function isRunnable(): bool
    {
        return $this->isValid() && ($this->dependencies() || $this->scripts());
    }

    /**
     * @return string|null
     */
    public function dependencies(): ?string
    {
        $this->parseData();
        $deps = $this->valid ? (string)$this->data[self::DEPENDENCIES] : null;
        ($deps === self::NONE) and $deps = null;

        return $deps;
    }

    /**
     * @param string $which
     * @return bool
     */
    public function dependenciesIs(string $which): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        return $this->dependencies() === $which;
    }

    /**
     * @return array<string>|null
     */
    public function scripts(): ?array
    {
        $this->parseData();
        if (!$this->valid) {
            return null;
        }

        /** @var array<string>|null $scripts */
        $scripts = $this->data[self::SCRIPT];

        return $scripts;
    }

    /**
     * The returned instance, if any, is not guaranteed to be valid/executable.
     *
     * @return PackageManager\PackageManager|null
     */
    public function packageManager(): ?PackageManager\PackageManager
    {
        $this->parseData();
        if (!$this->valid) {
            return null;
        }

        /** @var PackageManager\PackageManager|null $packageManager */
        $packageManager = $this->data[self::PACKAGE_MANAGER];

        return $packageManager;
    }

    /**
     * @return PreCompilation\Config|null
     */
    public function preCompilationConfig(): ?PreCompilation\Config
    {
        $this->parseData();
        if (!$this->valid) {
            return null;
        }

        /** @var PreCompilation\Config $config */
        $config = $this->data[self::PRE_COMPILED];

        return $config;
    }

    /**
     * @return array<string, string>
     */
    public function defaultEnv(): array
    {
        if (!$this->isValid() || !$this->byPackage) {
            return [];
        }

        if (is_array($this->data[self::DEF_ENV])) {
            /** @var array<string, string> $data */
            $data = $this->data[self::DEF_ENV];

            return $data;
        }

        $config = $this->raw[self::DEF_ENV] ?? null;
        $this->data[self::DEF_ENV] = (is_array($config) || ($config instanceof \stdClass))
            ? Env::sanitizeEnvVars((array)$config)
            : [];

        return $this->data[self::DEF_ENV];
    }

    /**
     * @return array<string, string>
     */
    public function mergedDefaultEnv(): array
    {
        if (!$this->isValid()) {
            return [];
        }

        $inner = $this->defaultEnv();
        if ($this->rootConfig()) {
            return $inner;
        }

        return array_merge(array_filter($this->rootEnv), array_filter($inner));
    }

    /**
     * @return bool|null
     */
    public function isolatedCache(): ?bool
    {
        $this->parseData();
        if (!$this->valid) {
            return null;
        }

        /** @var bool|null $isolated */
        $isolated = $this->data[self::ISOLATED_CACHE];

        return $isolated;
    }

    /**
     * @return list<string>
     */
    public function srcPaths(): array
    {
        $this->parseData();
        if (!$this->valid) {
            return [];
        }

        /** @var list<string> $paths */
        $paths = $this->data[self::SRC_PATHS];

        return $paths;
    }

    /**
     * @return void
     */
    private function parseData(): void
    {
        if ($this->dataWasParsed) {
            return;
        }

        $this->dataWasParsed = true;

        if ($this->maybeSpecialRootLevelPackageConfig()) {
            return;
        }

        $config = $this->raw;

        if (!$config) {
            $this->valid = false;

            return;
        }

        $this->data = self::BASE_DATA;
        $scripts = $this->parseScripts($config);
        $this->data[self::DEPENDENCIES] = $this->parseDependencies($config, (bool)$scripts);
        $this->data[self::SCRIPT] = $scripts;
        $this->data[self::PRE_COMPILED] = $this->parsePreCompiled($config);
        $this->data[self::PACKAGE_MANAGER] = $this->parsePackageManager($config);
        $this->data[self::ISOLATED_CACHE] = $this->parseIsolatedCache($config);
        $this->data[self::SRC_PATHS] = $this->parseLockPaths($config);

        $this->valid = $this->data[self::DEPENDENCIES] || $this->data[self::SCRIPT];
    }

    /**
     * @param array $config
     * @param bool $haveScripts
     * @return string|null
     */
    private function parseDependencies(array $config, bool $haveScripts): ?string
    {
        $default = $haveScripts ? self::INSTALL : null;
        $dependencies = $config[self::DEPENDENCIES] ?? $default;
        if ($dependencies === false || $dependencies === null) {
            $dependencies = self::NONE;
        }

        if (is_array($dependencies)) {
            $byMode = $this->modeResolver->resolveConfig($dependencies);
            $dependencies = ($byMode && is_string($byMode)) ? $byMode : null;
        }

        is_string($dependencies) and $dependencies = strtolower($dependencies);
        if (!in_array($dependencies, self::DEPENDENCIES_OPTIONS, true)) {
            $dependencies = null;
        }

        return $dependencies;
    }

    /**
     * @param array $config
     * @return array<string>|null
     */
    private function parseScripts(array $config): ?array
    {
        $scripts = $config[self::SCRIPT] ?? null;

        $oneScript = $scripts && is_string($scripts);
        if (!$scripts || (!$oneScript && !is_array($scripts))) {
            $scripts = null;
        }

        if ($scripts === null || $oneScript) {
            /** @var string $scripts */
            return $oneScript ? [$scripts] : null;
        }

        /** @var array $scripts */

        $byMode = $this->modeResolver->resolveConfig($scripts);
        if ($byMode && (is_array($byMode) || is_string($byMode))) {
            $scripts = (array)$byMode;
        } elseif ($byMode === null) {
            $scripts = $this->modeResolver->removeModeConfig($scripts);
        }

        $allScripts = [];
        foreach ($scripts as $script) {
            ($script && is_string($script)) and $allScripts[$script] = 1;
        }

        /** @var list<string> $keys */
        $keys = $allScripts ? array_keys($allScripts) : null;

        return $keys;
    }

    /**
     * @param array $config
     * @return PreCompilation\Config
     */
    private function parsePreCompiled(array $config): PreCompilation\Config
    {
        $raw = $config[self::PRE_COMPILED] ?? null;
        if (!$raw || !is_array($raw)) {
            return PreCompilation\Config::invalid();
        }

        return PreCompilation\Config::new($raw, $this->modeResolver);
    }

    /**
     * @param array $config
     * @return PackageManager\PackageManager|null
     */
    private function parsePackageManager(array $config): ?PackageManager\PackageManager
    {
        // 'commands' is deprecated, but we don't have an IO instance here to inform the user
        $manager = $config[self::PACKAGE_MANAGER] ?? $config['commands'] ?? null;

        if (!$manager) {
            $byEnv = $this->resolveByEnv('PACKAGE_MANAGER');
            if (!$byEnv) {
                return null;
            }

            $manager = $byEnv;
        }

        if (is_array($manager)) {
            $byMode = $this->modeResolver->resolveConfig($manager);
            if ($byMode && (is_array($byMode) || is_string($byMode))) {
                $manager = $byMode;
            } elseif ($byMode === null) {
                $manager = $this->modeResolver->removeModeConfig($manager);
            }
        }

        if (is_string($manager)) {
            return PackageManager\PackageManager::fromDefault(strtolower($manager));
        }

        return is_array($manager) ? PackageManager\PackageManager::new($manager) : null;
    }

    /**
     * @param array $config
     * @return bool|null
     */
    private function parseIsolatedCache(array $config): ?bool
    {
        $isolated = $config[self::ISOLATED_CACHE] ?? $this->resolveByEnv('ISOLATED_CACHE');
        if ($isolated === null) {
            return null;
        }

        if (is_array($isolated)) {
            $byMode = $this->modeResolver->resolveConfig($isolated);
            if ($byMode && (is_bool($byMode) || is_string($byMode))) {
                $isolated = $byMode;
            } elseif ($byMode === null) {
                return false;
            }
        }

        return (bool)filter_var($isolated, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array $config
     * @return list<string>
     */
    private function parseLockPaths(array $config): array
    {
        $paths = $config[self::SRC_PATHS] ?? null;

        if (is_array($paths)) {
            $byMode = $this->modeResolver->resolveConfig($paths);
            if ($byMode && (is_array($byMode) || is_string($byMode))) {
                $paths = $byMode;
            } elseif ($byMode === null) {
                $paths = $this->modeResolver->removeModeConfig($paths);
            }
        }

        is_string($paths) and $paths = [$paths];
        if (!is_array($paths)) {
            return [];
        }

        $parsed = [];
        foreach ($paths as $path) {
            ($path && is_string($path)) and $parsed[] = $path;
        }

        return $parsed;
    }

    /**
     * @return bool
     */
    private function maybeSpecialRootLevelPackageConfig(): bool
    {
        if ($this->isByPackage()) {
            return false;
        }

        foreach ([self::DISABLED, self::FORCE_DEFAULTS, self::BY_PACKAGE_OR_DEFAULTS] as $config) {
            if (($this->raw[$config] ?? null) === true) {
                $this->data = self::BASE_DATA;
                $this->data[$config] = true;
                $this->valid = true;

                return true;
            }
        }

        return false;
    }

    /**
     * @param string $key
     * @return string|null
     */
    private function resolveByEnv(string $key): ?string
    {
        return Env::readEnv("COMPOSER_ASSET_COMPILER_{$key}", $this->mergedDefaultEnv());
    }
}
