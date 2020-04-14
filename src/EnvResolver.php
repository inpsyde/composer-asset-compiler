<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

class EnvResolver
{
    public const ENV_DEFAULT = '$default';
    public const ENV_DEFAULT_NO_DEV = '$default-no-dev';

    private const ENV_KEY = 'env';

    /**
     * @var string|null
     */
    private $env;

    /**
     * @var bool
     */
    private $isDev;

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
     * @param array $vars
     * @return array<string, string>
     */
    public static function sanitizeEnvVars(array $vars): array
    {
        $sanitized = [];
        foreach ((array)$vars as $key => $value) {
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
     */
    public function __construct(?string $env, bool $isDev)
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
     *
     * @psalm-suppress MissingReturnType
     */
    public function resolve(array $config)
    {
        // phpcs:enable

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
}
