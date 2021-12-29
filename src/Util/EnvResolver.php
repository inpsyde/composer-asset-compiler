<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

class EnvResolver
{
    public const ENV_DEFAULT = '$default';
    public const ENV_DEFAULT_NO_DEV = '$default-no-dev';

    private const ENV_KEY = 'env';
    private const ENV_TYPE_VAR_NAME = 'COMPOSER_ASSETS_COMPILER';

    /**
     * @var string|null
     */
    private $env;

    /**
     * @var bool
     */
    private $isDev;

    /**
     * @return string|null
     */
    public static function assetsCompilerEnv(): ?string
    {
        return EnvResolver::readEnv(self::ENV_TYPE_VAR_NAME);
    }

    /**
     * @param string $name
     * @param array $defaults
     * @return string|null
     */
    public static function readEnv(string $name, array $defaults = []): ?string
    {
        $env = getenv($name);
        if ($env) {
            return $env;
        }

        $toCheck = [$_ENV];
        if (stripos($name, 'HTTP_') !== 0) {
            $toCheck[] = $_SERVER;
        }

        $toCheck[] = $defaults;

        foreach ($toCheck as $data) {
            $env = $data[$name] ?? null;
            if ($env && is_string($env)) {
                return $env;
            }
        }

        return null;
    }

    /**
     * @param string $string
     * @param array $defaultEnv
     * @return string
     */
    public static function replaceEnvVariables(string $string, array $defaultEnv): string
    {
        if (!$string || (strpos($string, '${') === false)) {
            return $string;
        }

        return (string)preg_replace_callback(
            '~\$\{([a-z0-9_]+)\}~i',
            static function (array $var) use ($defaultEnv): string {
                return (string)static::readEnv($var[1], $defaultEnv);
            },
            $string
        );
    }

    /**
     * @param array $vars
     * @return array<string, string>
     */
    public static function sanitizeEnvVars(array $vars): array
    {
        $sanitized = [];
        foreach ($vars as $key => $value) {
            if (
                $key
                && is_string($key)
                && is_string($value)
                && preg_match('/^[a-z_][a-z0-9_]*$/i', $key)
            ) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * @param string|null $env
     * @param bool $isDev
     * @return EnvResolver
     */
    public static function new(?string $env, bool $isDev): EnvResolver
    {
        return new static($env, $isDev);
    }

    /**
     * @param string|null $env
     * @param bool $isDev
     */
    final private function __construct(?string $env, bool $isDev)
    {
        $this->env = $env;
        $this->isDev = $isDev;
    }

    /**
     * @return string
     */
    public function env(): string
    {
        if ($this->env) {
            return $this->env;
        }

        return $this->isDev ? self::ENV_DEFAULT : self::ENV_DEFAULT_NO_DEV;
    }

    /**
     * @param array $config
     * @return mixed|null
     *
     * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
     */
    public function resolveConfig(array $config)
    {
        // phpcs:enable Inpsyde.CodeQuality.ReturnTypeDeclaration

        $envs = $config[self::ENV_KEY] ?? null;
        if (!$envs || !is_array($envs)) {
            return null;
        }

        $envCandidates = $this->env ? [$this->env] : [];
        $this->isDev or $envCandidates[] = self::ENV_DEFAULT_NO_DEV;
        $envCandidates[] = self::ENV_DEFAULT;

        foreach ($envCandidates as $envCandidate) {
            if (array_key_exists($envCandidate, $envs)) {
                return $envs[$envCandidate];
            }
        }

        return null;
    }

    /**
     * @param array $config
     * @return array
     */
    public function removeEnvConfig(array $config): array
    {
        return array_diff_key($config, [self::ENV_KEY => '']);
    }
}
