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

    /**
     * @var array
     */
    private $raw;

    /**
     * @var array{source:string, target:string, adapter:string|null, config:array}|null
     */
    private $parsed;

    /**
     * @var bool|null
     */
    private $valid;

    /**
     * @var EnvResolver|null
     */
    private $envResolver;

    /**
     * @param array $raw
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

        $rawSource = $this->parsed[self::SOURCE];

        return $placeholders->replace($rawSource, $environment) ?: null;
    }

    /**
     * @return string|null
     */
    public function target(): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        return $this->parsed[self::TARGET];
    }

    /**
     * @return string|null
     */
    public function adapter(): ?string
    {
        if (!$this->parse()) {
            return null;
        }

        return $this->parsed[self::ADAPTER];
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

        $raw = $this->parsed[self::CONFIG];
        $config = [];
        foreach ($raw as $key => $value) {
            if ($value && is_string($value)) {
                $value = $placeholders->replace($value, $environment);
            }

            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->parse() ? $this->parsed : [];
    }

    /**
     * @return bool
     *
     * @psalm-assert bool $this->valid
     * @psalm-assert-if-true array{source:string, target:string, adapter:string|null} $this->parsed
     * @psalm-assert-if-true EnvResolver $this->envResolver
     * @psalm-assert-if-false null $this->parsed
     *
     * phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh
     */
    private function parse(): bool
    {
        if (is_bool($this->valid)) {
            return $this->valid;
        }

        $this->parsed = null;
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

        $source = $config[self::SOURCE] ?? null;
        $target = $config[self::TARGET] ?? null;
        $adapter = $config[self::ADAPTER] ?? null;
        $config = $config[self::CONFIG] ?? [];

        $this->valid = $source
            && $target
            && is_string($source)
            && is_string($target)
            && (($adapter === null) || ($adapter && is_string($adapter)))
            && is_array($config);

        if (!$this->valid) {
            return false;
        }

        /**
         * @var string $source
         * @var string $target
         * @var string|null $adapter
         * @var array $config
         */

        $this->parsed = [
            self::SOURCE => $source,
            self::TARGET => $target,
            self::ADAPTER => $adapter ? strtolower($adapter) : null,
            self::CONFIG => $config,
        ];

        return true;
    }
}
