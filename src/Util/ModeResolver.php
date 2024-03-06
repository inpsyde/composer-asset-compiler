<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

class ModeResolver
{
    public const MODE_DEFAULT = '$default';
    public const MODE_DEFAULT_NO_DEV = '$default-no-dev';
    public const MODE_TYPE_VAR_NAME = 'COMPOSER_ASSETS_COMPILER';

    private const MODE_KEY = '$mode';
    private const MODE_KEY_LEGACY = 'env';

    /**
     * @param string|null $env
     * @param bool $isDev
     * @return ModeResolver
     */
    public static function new(?string $env, bool $isDev): ModeResolver
    {
        return new static($env, $isDev);
    }

    /**
     * @param string|null $mode
     * @param bool $isDev
     */
    final private function __construct(
        private ?string $mode,
        private bool $isDev
    ) {
    }

    /**
     * @return string
     */
    public function mode(): string
    {
        if (($this->mode !== null) && ($this->mode !== '')) {
            return $this->mode;
        }

        return $this->isDev ? self::MODE_DEFAULT : self::MODE_DEFAULT_NO_DEV;
    }

    /**
     * @param array $config
     * @return mixed
     */
    public function resolveConfig(array $config): mixed
    {
        $envs = $config[self::MODE_KEY] ?? $config[self::MODE_KEY_LEGACY] ?? null;
        if (($envs === null) || ($envs === []) || !is_array($envs)) {
            return null;
        }

        $envCandidates = ($this->mode !== null) ? [$this->mode] : [];
        $this->isDev or $envCandidates[] = self::MODE_DEFAULT_NO_DEV;
        $envCandidates[] = self::MODE_DEFAULT;

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
    public function removeModeConfig(array $config): array
    {
        return array_diff_key($config, [self::MODE_KEY => '', self::MODE_KEY_LEGACY => '']);
    }
}
