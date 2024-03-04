<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Semver\VersionParser;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Util\Env;

class Placeholders
{
    public const MODE = 'mode';
    public const HASH = 'hash';
    public const VERSION = 'version';
    public const REFERENCE = 'ref';

    private string $uid;

    /**
     * @param Asset $asset
     * @param string $env
     * @param string|null $hash
     * @return Placeholders
     */
    public static function new(Asset $asset, string $env, ?string $hash): Placeholders
    {
        return new self($env, $hash, $asset->version(), $asset->reference());
    }

    /**
     * @param string $mode
     * @param string|null $hash
     * @param string|null $version
     * @param string|null $reference
     */
    private function __construct(
        private string $mode,
        private ?string $hash,
        private ?string $version,
        private ?string $reference
    ) {

        $values = [$hash ?? '', $version ?? '', $reference ?? '', random_bytes(32)];
        $base = sha1(implode('|', $values));
        $last = sha1(implode('|', [substr($base, 32) ?: '', microtime()]));
        $this->uid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($base, 12, 8) ?: '',
            substr($base, 20, 4) ?: '',
            substr($base, 24, 4) ?: '',
            substr($base, 28, 4) ?: '',
            substr($last, 14, 12) ?: ''
        );
    }

    /**
     * @return string
     */
    public function uuid(): string
    {
        return $this->uid;
    }

    /**
     * @return bool
     */
    public function hasStableVersion(): bool
    {
        return ($this->version !== null)
            && ($this->version !== '')
            && (VersionParser::parseStability($this->version) === 'stable');
    }

    /**
     * @param string $original
     * @param array $environment
     * @return string
     */
    public function replace(string $original, array $environment): string
    {
        if (($original === '') || (!str_contains($original, '${'))) {
            return $original;
        }

        $replace = [
            self::HASH => $this->hash,
            self::MODE => $this->mode,
            self::VERSION => $this->version,
            self::REFERENCE => $this->reference,
        ];

        $replaced = preg_replace_callback(
            '~\$\{\s*(' . implode('|', array_keys($replace)) . ')\s*\}~i',
            static function (array $matches) use ($replace): string {
                $key = strtolower($matches[1] ?? '');

                return $replace[$key] ?? '';
            },
            $original
        );

        return ($replaced !== null) && ($replaced !== '')
            ? Env::replaceEnvVariables($replaced, $environment)
            : '';
    }
}
