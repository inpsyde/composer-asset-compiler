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
     * @var string|null
     */
    private $mode;

    /**
     * @var bool
     */
    private $isDev;

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
    final private function __construct(?string $mode, bool $isDev)
    {
        $this->mode = $mode;
        $this->isDev = $isDev;
    }

    /**
     * @return string
     */
    public function mode(): string
    {
        if ($this->mode) {
            return $this->mode;
        }

        return $this->isDev ? self::MODE_DEFAULT : self::MODE_DEFAULT_NO_DEV;
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

        $envs = $config[self::MODE_KEY] ?? $config[self::MODE_KEY_LEGACY] ?? null;
        if (!$envs || !is_array($envs)) {
            return null;
        }

        $envCandidates = $this->mode ? [$this->mode] : [];
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
