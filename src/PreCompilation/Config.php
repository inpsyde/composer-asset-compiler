<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Inpsyde\AssetsCompiler\Util\EnvResolver;

final class Config
{
    private const SOURCE = 'source';
    private const TARGET = 'target';
    private const ADAPTER = 'adapter';
    private const CONFIG = 'config';
    private const STABILITY = 'stability';
    private const STABILITY_STABLE = 'stable';
    private const STABILITY_DEV = 'dev';
    private const STABILITY_ALL = '*';

    /**
     * @var array
     */
    private $raw;

    /**
     * @var list<array<string, string|null|array>>
     */
    private $parsed = [];

    /**
     * @var array<string, array<string, string|null|array>>
     */
    private $selected = [];

    /**
     * @var bool|null
     */
    private $valid = null;

    /**
     * @var EnvResolver|null
     */
    private $envResolver;

    /**
     * @return Config
     */
    public static function invalid(): Config
    {
        return new static([]);
    }

    /**
     * @param array $raw
     * @param EnvResolver $envResolver
     * @return Config
     */
    public static function new(array $raw, EnvResolver $envResolver): Config
    {
        return new static($raw, $envResolver);
    }

    /**
     * @param array $raw
     * @param EnvResolver|null $envResolver
     */
    private function __construct(array $raw, ?EnvResolver $envResolver = null)
    {
        $this->raw = $raw;
        $this->envResolver = $envResolver;
    }

    /**
     * @return bool
     *
     * @psalm-assert-if-true EnvResolver $this->envResolver
     */
    public function isValid(): bool
    {
        return $this->parse();
    }

    /**
     * @param Placeholders $placeholders
     * @param array $environment
     * @return string|null
     */
    public function source(Placeholders $placeholders, array $environment): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        $this->selectBestSettings($placeholders);

        /** @var string|null $rawSource */
        $rawSource = $this->selected[$placeholders->uuid()][self::SOURCE] ?? null;

        return $rawSource ? ($placeholders->replace($rawSource, $environment) ?: null) : null;
    }

    /**
     * @param Placeholders $placeholders
     * @return string|null
     */
    public function target(Placeholders $placeholders): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        $this->selectBestSettings($placeholders);

        /** @var string|null $target */
        $target = $this->selected[$placeholders->uuid()][self::TARGET] ?? null;

        return $target;
    }

    /**
     * @param Placeholders $placeholders
     * @return string|null
     */
    public function adapter(Placeholders $placeholders): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        $this->selectBestSettings($placeholders);

        /** @var string|null $adapter */
        $adapter = $this->selected[$placeholders->uuid()][self::ADAPTER] ?? null;

        return $adapter;
    }

    /**
     * @param Placeholders $placeholders
     * @param array $environment
     * @return array
     */
    public function config(Placeholders $placeholders, array $environment): array
    {
        if (!$this->parse()) {
            return [];
        }

        $this->selectBestSettings($placeholders);

        /** @var array $raw */
        $raw = $this->selected[$placeholders->uuid()][self::CONFIG] ?? [];

        return $this->deepReplace($raw, $placeholders, $environment);
    }

    /**
     * @param array $data
     * @param Placeholders $placeholders
     * @param array $env
     * @return array
     */
    private function deepReplace(array $data, Placeholders $placeholders, array $env): array
    {
        $config = [];
        foreach ($data as $key => $value) {
            if (!$value) {
                $config[$key] = $value;
                continue;
            }
            if (is_string($value)) {
                $config[$key] = $placeholders->replace($value, $env);
                continue;
            }
            $config[$key] = is_array($value)
                ? $this->deepReplace($value, $placeholders, $env)
                : $value;
        }

        return $config;
    }

    /**
     * @return bool
     */
    private function parse(): bool
    {
        if (is_bool($this->valid)) {
            return $this->valid;
        }

        $this->valid = false;

        if (!$this->envResolver) {
            return false;
        }

        $config = $this->raw;
        if (!$config) {
            return false;
        }

        $byEnv = $this->envResolver->resolveConfig($config);
        if ($byEnv && is_array($byEnv)) {
            $config = $byEnv;
        }
        if ($byEnv === null) {
            $config = $this->envResolver->removeEnvConfig($config);
        }

        $settings = [];
        $isNumeric = strpos(@json_encode($config) ?: '', '[') === 0;
        $hasSource = array_key_exists(self::SOURCE, $config);
        if ($isNumeric && !$hasSource) {
            $settings = $config;
        } elseif ($hasSource) {
            $settings = [$config];
        }

        foreach ($settings as $setting) {
            is_array($setting) and $this->parseSetting($setting);
        }

        $this->valid = count($this->parsed) > 0;

        return $this->valid;
    }

    /**
     * @param array $setting
     * @return void
     */
    private function parseSetting(array $setting): void
    {
        $source = $setting[self::SOURCE] ?? null;
        $target = $setting[self::TARGET] ?? null;
        $adapter = $setting[self::ADAPTER] ?? null;
        $stability = $setting[self::STABILITY] ?? null;
        $config = $setting[self::CONFIG] ?? [];

        $valid = $source
            && $target
            && is_string($source)
            && is_string($target)
            && (($adapter === null) || ($adapter && is_string($adapter)))
            && is_array($config);

        if (!$valid) {
            return;
        }

        is_string($stability) and $stability = strtolower($stability);
        if (!in_array($stability, [self::STABILITY_DEV, self::STABILITY_STABLE], true)) {
            $stability = self::STABILITY_ALL;
        }

        $this->parsed[] = [
            self::SOURCE => $source,
            self::TARGET => $target,
            self::ADAPTER => $adapter ? strtolower($adapter) : null,
            self::STABILITY => $stability,
            self::CONFIG => $config,
        ];
    }

    /**
     * @param Placeholders $placeholders
     * @return void
     *
     * @psalm-assert array<string, string|null|array> $this->selected
     */
    private function selectBestSettings(Placeholders $placeholders): void
    {
        $uuid = $placeholders->uuid();
        if (isset($this->selected[$uuid])) {
            return;
        }

        $isStable = $placeholders->hasStableVersion();
        $acceptedStability = $isStable ? self::STABILITY_STABLE : self::STABILITY_DEV;
        $noHash = $placeholders->replace('${hash}', []) === '';
        $noVersion = $placeholders->replace('${version}', []) === '';
        $noReference = $placeholders->replace('${ref}', []) === '';

        /** @var array<string, string|null|array>|null $exactStability */
        $exactStability = null;
        /** @var array<string, string|null|array>|null $fallbackStability */
        $fallbackStability = null;

        foreach ($this->parsed as $settings) {
            if (
                ($noHash && $this->containsPlaceholder('${hash}', $settings))
                || ($noVersion && $this->containsPlaceholder('${version}', $settings))
                || ($noReference && $this->containsPlaceholder('${ref}', $settings))
            ) {
                continue;
            }

            if (!$exactStability && ($settings[self::STABILITY] === $acceptedStability)) {
                $exactStability = $settings;
            } elseif (!$fallbackStability && ($settings[self::STABILITY] === self::STABILITY_ALL)) {
                $fallbackStability = $settings;
            }

            if ($exactStability && $fallbackStability) {
                break;
            }
        }

        $this->selected[$uuid] = $exactStability ?? $fallbackStability ?? [];
    }

    /**
     * @param string $placeholder
     * @param array $setting
     * @return bool
     */
    private function containsPlaceholder(string $placeholder, array $setting): bool
    {
        /** @var string $source */
        $source = $setting[self::SOURCE] ?? '';
        if (stripos(str_replace(' ', '', $source), $placeholder) !== false) {
            return true;
        }

        /** @var array $config */
        $config = $setting[self::CONFIG] ?? [];
        foreach ($config as $value) {
            if (
                is_string($value)
                && (stripos(str_replace(' ', '', $value), $placeholder) !== false)
            ) {
                return true;
            }
        }

        return false;
    }
}
