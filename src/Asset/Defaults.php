<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

/**
 * @template T of Config|null
 */
final class Defaults
{
    /**
     * @var T
     */
    private $config;

    /**
     * @return Defaults
     */
    public static function empty(): Defaults
    {
        return new static(null);
    }

    /**
     * @param Config $config
     * @return Defaults
     */
    public static function new(Config $config): Defaults
    {
        return new static($config);
    }

    /**
     * @param Config|null $config
     */
    private function __construct(?Config $config)
    {
        /** @var T config */
        $this->config = $config;
    }

    /**
     * @psalm-assert-if-true Defaults<Config> $this
     * @psalm-assert-if-true Config $this->config
     */
    public function isValid(): bool
    {
        return $this->config && $this->config->isRunnable();
    }

    /**
     * @return Config|null
     */
    public function toConfig(): ?Config
    {
        return $this->isValid() ? $this->config : null;
    }
}
