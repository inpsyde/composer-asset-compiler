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
 * @template-covariant T of Config|null
 */
final class Defaults
{
    /**
     * @return Defaults<null>
     */
    public static function newEmpty(): Defaults
    {
        return new self(null);
    }

    /**
     * @param Config $config
     * @return Defaults<Config>
     */
    public static function new(Config $config): Defaults
    {
        return new self($config);
    }

    /**
     * @param T $config
     */
    private function __construct(private ?Config $config)
    {
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
