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

class PackageConfig
{

    public const EXTRA_KEY = 'composer-asset-compiler';
    public const DEF_ENV = 'default-env';
    public const DEPENDENCIES = 'dependencies';
    public const SCRIPT = 'script';
    public const INSTALL = 'install';
    public const UPDATE = 'update';

    private const FORCE_DEFAULTS = 'force-defaults';
    private const BY_PACKAGE_OR_DEFAULTS = 'by-defaults';
    private const BY_DEFAULTS_FORCED = self::FORCE_DEFAULTS;
    private const DISABLED = 'disabled';

    /**
     * @var bool
     */
    private $isPackage;

    /**
     * @var \Inpsyde\AssetsCompiler\EnvResolver
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
    private $data = [
        self::DEPENDENCIES => null,
        self::SCRIPT => null,
        self::DEF_ENV => null,
        self::BY_PACKAGE_OR_DEFAULTS => false,
        self::BY_DEFAULTS_FORCED => false,
        self::DISABLED => false,
    ];

    /**
     * @param mixed $data
     * @param \Inpsyde\AssetsCompiler\EnvResolver $envResolver
     * @return \Inpsyde\AssetsCompiler\PackageConfig
     *
     * @psalm-suppress MissingParamType
     * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
     */
    public static function forRawPackageData($data, EnvResolver $envResolver): PackageConfig
    {
        // phpcs:enable Inpsyde.CodeQuality.ArgumentTypeDeclaration

        if (!is_array($data) && !is_bool($data) && ($data !== self::FORCE_DEFAULTS)) {
            $data = [];
        }

        is_scalar($data) and $data = [$data];

        return new static(false, $data, $envResolver);
    }

    /**
     * @param \Composer\Package\PackageInterface $package
     * @param \Inpsyde\AssetsCompiler\EnvResolver $envResolver
     * @return \Inpsyde\AssetsCompiler\PackageConfig
     */
    public static function forComposerPackage(
        PackageInterface $package,
        EnvResolver $envResolver
    ): PackageConfig {

        $raw = $package->getExtra()[self::EXTRA_KEY] ?? [];
        is_array($raw) or $raw = [];

        return new static(true, $raw, $envResolver);
    }

    /**
     * @param bool $isPackage
     * @param bool $isRootPackage
     * @param array $raw
     * @param EnvResolver $envResolver
     */
    private function __construct(bool $isPackage, array $raw, EnvResolver $envResolver)
    {
        $this->isPackage = $isPackage;
        $this->raw = $raw;
        $this->envResolver = $envResolver;
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        $this->parseData();

        return $this->valid;
    }

    /**
     * @return bool
     */
    public function isPackage(): bool
    {
        return $this->isPackage;
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        if ($this->isPackage()) {
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
        if ($this->isPackage()) {
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
        if ($this->isPackage()) {
            return false;
        }

        $this->parseData();

        return (bool)$this->data[self::BY_DEFAULTS_FORCED];
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

        return $this->valid ? (string)$this->data[self::DEPENDENCIES] : null;
    }

    /**
     * @return string|null
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

        return $this->valid ? (array)$this->data[self::SCRIPT] : null;
    }

    /**
     * @return array<string, string>
     */
    public function defaultEnv(): array
    {
        if (!$this->isPackage()) {
            return [];
        }

        if (is_array($this->data[self::DEF_ENV])) {
            return $this->data[self::DEF_ENV];
        }

        $this->data[self::DEF_ENV] = [];

        $this->parseData();

        if (!$this->valid) {
            return [];
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
            $this->valid = true;

            return;
        }

        $config = $this->raw;

        if (!$config) {
            $this->valid = false;

            return;
        }

        $byEnv = $this->envResolver->resolve($this->raw);

        if ($byEnv && is_array($byEnv)) {
            $config = $byEnv;
        }

        if ($byEnv === null) {
            $config = $this->envResolver->removeEnv($config);
        }

        $this->data[self::DEPENDENCIES] = $this->parseDependencies($config);
        $this->data[self::SCRIPT] = $this->parseScripts($config);

        $this->valid = $this->data[self::DEPENDENCIES] || $this->data[self::SCRIPT];
    }

    /**
     * @param array $config
     * @return string|null
     */
    private function parseDependencies(array $config): ?string
    {
        $dependencies = $config[self::DEPENDENCIES] ?? null;

        if (
            $dependencies !== self::UPDATE
            && $dependencies !== self::INSTALL
        ) {
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
     * @return bool
     */
    private function maybeSpecialRootLevelPackageConfig(): bool
    {
        if ($this->isPackage()) {
            return false;
        }

        $config = count($this->raw) === 1 ? ($this->raw[0] ?? null) : null;

        $special = false;
        if ($config !== null) {
            $special = in_array($config, [true, false, self::FORCE_DEFAULTS], true);
            if ($special) {
                $this->data[self::BY_DEFAULTS_FORCED] = $config === self::FORCE_DEFAULTS;
                $this->data[self::BY_PACKAGE_OR_DEFAULTS] = $config === true;
                $this->data[self::DISABLED] = $config === false;
            }
        }

        return $special;
    }
}
