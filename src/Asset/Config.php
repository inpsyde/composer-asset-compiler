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
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\PreCompilation;

class Config
{
    public const EXTRA_KEY = 'composer-asset-compiler';
    public const DEF_ENV = 'default-env';
    public const DEPENDENCIES = 'dependencies';
    public const SCRIPT = 'script';
    public const PRE_COMPILED = 'pre-compiled';
    public const INSTALL = 'install';
    public const UPDATE = 'update';
    public const NONE = 'none';

    private const FORCE_DEFAULTS = 'force-defaults';
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
    ];

    /**
     * @var bool
     */
    private $byPackage;

    /**
     * @var bool
     */
    private $byRootPackage = false;

    /**
     * @var EnvResolver
     */
    private $envResolver;

    /**
     * @var array
     */
    private $raw;

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
     * @param mixed $config
     * @param EnvResolver $envResolver
     * @return Config
     *
     * @psalm-suppress MissingParamType
     * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
     */
    public static function forAssetConfigInRoot($config, EnvResolver $envResolver): Config
    {
        // phpcs:enable Inpsyde.CodeQuality.ArgumentTypeDeclaration

        if (!is_array($config) && !is_bool($config) && !is_string($config)) {
            $config = [];
        }

        ($config === true) and $config = [self::BY_PACKAGE_OR_DEFAULTS => true];
        ($config === false) and $config = [self::DISABLED => true];
        ($config === self::FORCE_DEFAULTS) and $config = [self::FORCE_DEFAULTS => true];
        is_string($config) and $config = [
            self::DEPENDENCIES => self::INSTALL,
            self::SCRIPT => $config,
        ];

        return new static(false, $config, $envResolver);
    }

    /**
     * @param PackageInterface $package
     * @param EnvResolver $envResolver
     * @param string $configFile
     * @return Config
     */
    public static function forComposerPackage(
        PackageInterface $package,
        EnvResolver $envResolver,
        string $configFile
    ): Config {

        $raw = file_exists($configFile)
            ? JsonFile::parseJson(file_get_contents($configFile) ?: '')
            : $package->getExtra()[self::EXTRA_KEY] ?? [];

        switch (true) {
            case ($raw === false):
            case ($raw === self::DISABLED):
            case ($raw === self::FORCE_DEFAULTS):
            case ($raw === self::BY_PACKAGE_OR_DEFAULTS):
                $raw = [];
                break;
            case (is_string($raw)):
                $raw = [self::DEPENDENCIES => self::INSTALL, self::SCRIPT => $raw];
                break;
        }

        is_array($raw) or $raw = [];

        $instance = new static(true, $raw, $envResolver);
        $instance->byRootPackage = $package instanceof RootPackageInterface;

        return $instance;
    }

    /**
     * @param bool $byPackage
     * @param bool $isRootPackage
     * @param array $raw
     * @param EnvResolver $envResolver
     */
    final private function __construct(bool $byPackage, array $raw, EnvResolver $envResolver)
    {
        $this->byPackage = $byPackage;
        $this->raw = $raw;
        $this->envResolver = $envResolver;
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
     * @return PreCompilation\Config|null
     */
    public function preCompilationConfig(): ?PreCompilation\Config
    {
        $this->parseData();
        if (!$this->valid) {
            return null;
        }

        /** @var PreCompilation\Config $config */
        $config =  $this->data[self::PRE_COMPILED];

        return $config;
    }

    /**
     * @return array<string, string>
     */
    public function defaultEnv(): array
    {
        if (!$this->isByPackage()) {
            return [];
        }

        if (is_array($this->data[self::DEF_ENV])) {
            /** @var array<string, string> $env */
            $env = EnvResolver::sanitizeEnvVars($this->data[self::DEF_ENV]);

            return $env;
        }

        $this->data[self::DEF_ENV] = [];

        if (!$this->byRootPackage) {
            $this->parseData();

            if (!$this->valid) {
                return [];
            }
        }

        $config = $this->raw[self::DEF_ENV] ?? null;
        if (!is_array($config) && !$config instanceof \stdClass) {
            return [];
        }

        $this->data[self::DEF_ENV] = EnvResolver::sanitizeEnvVars((array)$config);

        return $this->data[self::DEF_ENV];
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

        $byEnv = $this->envResolver->resolveConfig($config);

        if ($byEnv && is_array($byEnv)) {
            $config = $byEnv;
        }

        if ($byEnv === null) {
            $config = $this->envResolver->removeEnvConfig($config);
        }

        $this->data = self::BASE_DATA;
        $scripts = $this->parseScripts($config);
        $this->data[self::DEPENDENCIES] = $this->parseDependencies($config, (bool)$scripts);
        $this->data[self::SCRIPT] = $scripts;
        $this->data[self::PRE_COMPILED] = $this->parsePreCompiled($config);

        $this->valid = $this->data[self::DEPENDENCIES] || $this->data[self::SCRIPT];
    }

    /**
     * @param array $config
     * @return string|null
     */
    private function parseDependencies(array $config, bool $haveScripts): ?string
    {
        $default = $haveScripts ? self::INSTALL : null;
        $dependencies = $config[self::DEPENDENCIES] ?? $default;
        if ($dependencies === false || $dependencies === null) {
            $dependencies = self::NONE;
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

        $oneScript = $scripts ? is_string($scripts) : false;
        if (!$scripts || (!$oneScript && !is_array($scripts))) {
            $scripts = null;
        }

        if ($scripts === null || $oneScript) {
            /** @var string $scripts */
            return $oneScript ? [$scripts] : null;
        }

        $allScripts = [];
        foreach ((array)$scripts as $script) {
            ($script && is_string($script)) and $allScripts[$script] = 1;
        }

        /** @var array<string> $keys */
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

        return PreCompilation\Config::new($raw, $this->envResolver);
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
}
