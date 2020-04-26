<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

/**
 * @template T of PackageConfig|null
 */
class Defaults
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
     * @param PackageConfig $config
     * @return Defaults
     */
    public static function new(PackageConfig $config): Defaults
    {
        return new static($config);
    }

    /**
     * @param PackageConfig|null $config
     */
    private function __construct(?PackageConfig $config)
    {
        /** @var T config */
        $this->config = $config;
    }

    /**
     * @psalm-assert-if-true Defaults<PackageConfig> $this
     * @psalm-assert-if-true PackageConfig $this->config
     */
    public function isValid(): bool
    {
        return $this->config && $this->config->isRunnable();
    }

    /**
     * @return \Inpsyde\AssetsCompiler\PackageConfig|null
     */
    public function toConfig(): ?PackageConfig
    {
        return $this->isValid() ? $this->config : null;
    }
}
