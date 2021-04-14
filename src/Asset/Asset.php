<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Inpsyde\AssetsCompiler\PreCompilation;
use Inpsyde\AssetsCompiler\Util\EnvResolver;

final class Asset
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var Config|null
     */
    private $config = null;

    /**
     * @var string|null
     */
    private $folder = null;

    /**
     * @var bool|null
     */
    private $valid;

    /**
     * @var string|null
     */
    private $version;

    /**
     * @param string $name
     * @param Config $config
     * @param string|null $folder
     * @param string|null $version
     * @return Asset
     */
    public static function new(
        string $name,
        Config $config,
        ?string $folder = null,
        ?string $version = null
    ): Asset {

        $asset = new static($name);
        $asset->folder = $folder ? rtrim($folder, '/') : null;
        $asset->config = $config;
        $asset->version = $version;

        return $asset;
    }

    /**
     * @param string $name
     */
    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * @return bool
     *
     * @psalm-assert-if-true string $this->name
     * @psalm-assert-if-true string $this->folder
     */
    public function isValid(): bool
    {
        if (is_bool($this->valid)) {
            return $this->valid;
        }

        if (!$this->name || !$this->folder) {
            $this->valid = false;

            return false;
        }

        if (!$this->config || !$this->config->isRunnable()) {
            $this->valid = false;

            return false;
        }

        $this->valid = true;

        return true;
    }

    /**
     * @return bool
     */
    public function isInstall(): bool
    {
        return $this->config && ($this->config->dependenciesIs(Config::INSTALL));
    }

    /**
     * @return bool
     */
    public function isUpdate(): bool
    {
        return $this->config && ($this->config->dependenciesIs(Config::UPDATE));
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return string|null
     */
    public function path(): ?string
    {
        return $this->folder;
    }

    /**
     * @return string|null
     */
    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * @return array<string>
     */
    public function script(): array
    {
        if (!$this->config) {
            return [];
        }

        $scripts = $this->config->scripts();

        return $scripts ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function env(): array
    {
        return $this->config ? $this->config->defaultEnv() : [];
    }

    /**
     * @return PreCompilation\Config
     */
    public function preCompilationConfig(): PreCompilation\Config
    {
        if (!$this->config || EnvResolver::readEnv('COMPOSER_ASSET_COMPILE_PRECOMPILING')) {
            return PreCompilation\Config::invalid();
        }

        return $this->config->preCompilationConfig() ?? PreCompilation\Config::invalid();
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        if (!$this->config || !$this->isValid()) {
            return [];
        }

        $scripts = $this->script();
        if (count($scripts) === 1) {
            $scripts = reset($scripts);
        }

        $data = [
            Config::DEPENDENCIES => $this->config->dependencies(),
            Config::SCRIPT => $scripts,
        ];

        return array_filter($data);
    }
}
