<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

class EnvResolver
{
    private const ENV_KEY = 'env';
    private const ENV_DEFAULT = '$default';
    private const ENV_DEFAULT_NO_DEV = '$default-no-dev';

    /**
     * @var string|null
     */
    private $env;

    /**
     * @var bool
     */
    private $isDev;

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

        return $this->isDev ? self::ENV_DEFAULT_NO_DEV : self::ENV_DEFAULT;
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
