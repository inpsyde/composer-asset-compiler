<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

class Package implements \JsonSerializable
{
    public const DEPENDENCIES = 'dependencies';
    public const DEPENDENCIES_INSTALL = 'install';
    public const DEPENDENCIES_UPDATE = 'update';
    public const SCRIPT = 'script';

    /**
     * @var string|null
     */
    private static $defaultName;

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
     * @var array<string, string>
     */
    private $env;

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
        return new static(self::defaultName(), $config, null);
    }

    /**
     * @return string
     */
    private static function defaultName(): string
    {
        if (self::$defaultName === null) {
            self::$defaultName = uniqid('~~defaults~~');
        }

        return self::$defaultName;
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

        $envRaw = $config[Config::DEF_ENV] ?? null;
        if ($envRaw && (is_array($envRaw) || $envRaw instanceof \stdClass)) {
            $env = EnvResolver::sanitizeEnvVars((array)$envRaw);
        }

        $this->dependencies = $dependencies;
        $this->script = array_values(array_filter($script));
        $this->env = $env ?? [];
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        if (is_bool($this->isValid)) {
            return $this->isValid;
        }

        if (!$this->isInstall() && !$this->isUpdate() && !$this->script()) {
            $this->isValid = false;

            return false;
        }

        $this->isValid = ($this->name && $this->folder) || $this->isDefault();

        return $this->isValid;
    }

    /**
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->name === self::defaultName();
    }

    /**
     * @return bool
     */
    public function isInstall(): bool
    {
        return $this->dependencies === self::DEPENDENCIES_INSTALL;
    }

    /**
     * @return bool
     */
    public function isUpdate(): bool
    {
        return $this->dependencies === self::DEPENDENCIES_UPDATE;
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
        return $this->isDefault() ? null : $this->folder;
    }

    /**
     * @return string[]
     */
    public function script(): array
    {
        return $this->script;
    }

    /**
     * @return array<string, string>
     */
    public function env(): array
    {
        return $this->env;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $dependencies = null;
        if ($this->isUpdate()) {
            $dependencies = self::DEPENDENCIES_UPDATE;
        } elseif ($this->isInstall()) {
            $dependencies = self::DEPENDENCIES_INSTALL;
        }

        $scripts = $this->script();

        if (count($scripts) === 1) {
            $scripts = reset($scripts);
        }

        $data = [
            self::DEPENDENCIES => $dependencies ?? null,
            self::SCRIPT => $scripts ?: null,
        ];

        return array_filter($data);
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
