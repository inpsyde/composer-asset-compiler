<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

class Package
{
    private const DEPENDENCIES = 'dependencies';
    private const DEPENDENCIES_INSTALL = 'install';
    private const DEPENDENCIES_UPDATE = 'update';
    private const SCRIPT = 'script';
    private const DEFAULTS_NAME = '~~defaults~~';

    /**
     * @var string
     */
    private $name;

    /**
     * @var string|null
     */
    private $dependencies;

    /**
     * @var string[]
     */
    private $script;

    /**
     * @var string|null
     */
    private $folder;

    /**
     * @var bool|null
     */
    private $isValid;

    /**
     * @return array
     */
    public static function emptyConfig(): array
    {
        return [
            self::DEPENDENCIES => null,
            self::SCRIPT => null,
        ];
    }

    /**
     * @param array $config
     * @return Package
     */
    public static function defaults(array $config): Package
    {
        return new static(self::DEFAULTS_NAME, $config, null);
    }

    /**
     * @return Package
     */
    public static function createInvalid(): Package
    {
        return new static('', [], null);
    }

    /**
     * @param string $name
     * @param array $config
     * @param string|null $folder
     */
    public function __construct(string $name, array $config, string $folder = null)
    {
        $this->name = $name;
        $this->folder = $folder ? rtrim($folder, '/') : null;

        $dependencies = $config[self::DEPENDENCIES] ?? null;
        ($dependencies && is_string($dependencies)) or $dependencies = null;

        /** @var string[] $script */
        $script = array_filter((array)($config[self::SCRIPT] ?? []), 'is_string');

        $this->dependencies = $dependencies;
        $this->script = array_filter($script);
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        if (is_bool($this->isValid)) {
            return $this->isValid;
        }

        if (!$this->name || (!$this->install() && !$this->update() && !$this->script)) {
            $this->isValid = false;

            return false;
        }

        if ($this->isDefault()) {
            $this->isValid = true;

            return true;
        }

        $this->isValid = $this->folder
            && is_dir($this->folder)
            && file_exists("{$this->folder}/package.json");

        return $this->isValid;
    }

    /**
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->name === self::DEFAULTS_NAME;
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
        return $this->name === self::DEFAULTS_NAME ? null : $this->folder;
    }

    /**
     * @return bool
     */
    public function install(): bool
    {
        return $this->dependencies === self::DEPENDENCIES_INSTALL;
    }

    /**
     * @return bool
     */
    public function update(): bool
    {
        return $this->dependencies === self::DEPENDENCIES_UPDATE;
    }

    /**
     * @return string[]
     */
    public function script(): array
    {
        return $this->script;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        if ($this->update()) {
            $dependencies = self::DEPENDENCIES_UPDATE;
        } elseif ($this->install()) {
            $dependencies = self::DEPENDENCIES_INSTALL;
        }

        return [
            self::DEPENDENCIES => $dependencies ?? null,
            self::SCRIPT => $this->script(),
        ];
    }
}
