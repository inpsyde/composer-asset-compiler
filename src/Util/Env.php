<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

class Env
{
    /**
     * @return string|null
     */
    public static function assetsCompilerMode(): ?string
    {
        return static::readEnv(ModeResolver::MODE_TYPE_VAR_NAME);
    }

    /**
     * @param string $name
     * @param array $defaults
     * @return string|null
     */
    public static function readEnv(string $name, array $defaults = []): ?string
    {
        $env = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        if ($env === null) {
            $env = getenv($name);
            ($env === false) and $env = null;
        }

        $env ??= ($defaults[$name] ?? null);

        return is_string($env) ? $env : null;
    }

    /**
     * @param string $string
     * @param array $defaultEnv
     * @return string
     */
    public static function replaceEnvVariables(string $string, array $defaultEnv): string
    {
        if (!$string || !str_contains($string, '${')) {
            return $string;
        }

        return (string) preg_replace_callback(
            '~\$\{([a-z0-9_]+)\}~i',
            static function (array $var) use ($defaultEnv): string {
                return (string) static::readEnv($var[1], $defaultEnv);
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
}
