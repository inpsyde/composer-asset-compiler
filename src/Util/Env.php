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
     * @var \ArrayAccess|null
     */
    private static $getenvWrap = null;

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
        foreach ([$_SERVER, $_ENV, static::getenvWrap(), $defaults] as $data) {
            $env = $data[$name] ?? null;
            if (is_string($env)) {
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
     * @return \ArrayAccess
     *
     * phpcs:disable Inpsyde.CodeQuality.NestingLevel
     */
    private static function getenvWrap(): \ArrayAccess
    {
        // phpcs:enable Inpsyde.CodeQuality.NestingLevel
        if (!static::$getenvWrap) {
            // phpcs:disable
            static::$getenvWrap = new class() implements \ArrayAccess
            {
                public function offsetExists($offset)
                {
                    return is_string($offset) && (getenv($offset) !== false);
                }

                #[\ReturnTypeWillChange]
                public function offsetGet($offset)
                {
                    return is_string($offset) ? (getenv($offset) ?: null) : null;
                }

                public function offsetSet($offset, $value) {}

                public function offsetUnset($offset) {}
            };
            // phpcs:enable
        }

        return static::$getenvWrap;
    }
}
