<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Inpsyde\AssetsCompiler\Util\ModeResolver;

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

    /** @var list<array<string, string|null|array>> */
    private array $parsed = [];

    /** @var array<string, array<string, string|null|array>> */
    private array $selected = [];

    private bool|null $valid = null;

    /**
     * @return Config
     */
    public static function newInvalid(): Config
    {
        return new static([]);
    }

    /**
     * @param array $raw
     * @param ModeResolver $modeResolver
     * @return Config
     */
    public static function new(array $raw, ModeResolver $modeResolver): Config
    {
        return new static($raw, $modeResolver);
    }

    /**
     * @param array $raw
     * @param ModeResolver|null $modeResolver
     */
    private function __construct(
        private array $raw,
        private ?ModeResolver $modeResolver = null
    ) {
    }

    /**
     * @return bool
     *
     * @psalm-assert-if-true ModeResolver $this->modeResolver
     */
    public function isValid(): bool
    {
        return $this->parse();
    }

    /**
     * @param Placeholders $placeholders
     * @param array $environment
     * @return non-empty-string|null
     */
    public function source(Placeholders $placeholders, array $environment): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        $this->selectBestSettings($placeholders);

        /** @var string|null $rawSource */
        $rawSource = $this->selected[$placeholders->uuid()][self::SOURCE] ?? null;
        if (($rawSource === null) || ($rawSource === '')) {
            return null;
        }

        $replaced = $placeholders->replace($rawSource, $environment);

        return ($replaced === '') ? null : $replaced;
    }

    /**
     * @param Placeholders $placeholders
     * @return non-empty-string|null
     */
    public function target(Placeholders $placeholders): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        $this->selectBestSettings($placeholders);

        /** @var string|null $target */
        $target = $this->selected[$placeholders->uuid()][self::TARGET] ?? null;

        return ($target === '') ? null : $target;
    }

    /**
     * @param Placeholders $placeholders
     * @return non-empty-string|null
     */
    public function adapter(Placeholders $placeholders): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        $this->selectBestSettings($placeholders);

        /** @var string|null $adapter */
        $adapter = $this->selected[$placeholders->uuid()][self::ADAPTER] ?? null;

        return ($adapter === '') ? null : $adapter;
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
     * @param iterable $data
     * @param Placeholders $placeholders
     * @param array $env
     * @return array
     */
    private function deepReplace(iterable $data, Placeholders $placeholders, array $env): array
    {
        $config = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                /** @psalm-suppress MixedArrayOffset */
                $config[$key] = $value ? $placeholders->replace($value, $env) : '';
                continue;
            }

            $stdClass = $value instanceof \stdClass;
            if (!is_iterable($value) && !$stdClass) {
                /** @psalm-suppress MixedArrayOffset */
                $config[$key] = $value;
                continue;
            }

            /** @psalm-suppress MixedArgument */
            $stdClass and $value = get_object_vars($value);
            $replaced = $this->deepReplace($value, $placeholders, $env);
            $stdClass and $replaced = (object) $replaced;
            /** @psalm-suppress MixedArrayOffset */
            $config[$key] = $replaced;
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

        if (!$this->modeResolver) {
            return false;
        }

        $config = $this->raw;
        if (!$config) {
            return false;
        }

        $byMode = $this->modeResolver->resolveConfig($config);
        if (($byMode !== []) && is_array($byMode)) {
            $config = $byMode;
        }
        if ($byMode === null) {
            $config = $this->modeResolver->removeModeConfig($config);
        }

        $settings = [];
        $arrayIsList = str_starts_with((string) @json_encode($config), '[');
        $hasSource = array_key_exists(self::SOURCE, $config);
        if ($arrayIsList && !$hasSource) {
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

        $valid = ($source !== '')
            && ($target !== '')
            && is_string($source)
            && is_string($target)
            && (($adapter === null) || (($adapter !== '') && is_string($adapter)))
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
            self::ADAPTER => ($adapter !== null) ? strtolower($adapter) : null,
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

            if (($exactStability === null) && ($settings[self::STABILITY] === $acceptedStability)) {
                $exactStability = $settings;
            } elseif (
                ($fallbackStability === null)
                && ($settings[self::STABILITY] === self::STABILITY_ALL)
            ) {
                $fallbackStability = $settings;
            }

            if (($exactStability !== null) && ($fallbackStability !== null)) {
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
