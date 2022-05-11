<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Inpsyde\AssetsCompiler\Util\Env;

class GitHubConfig
{
    private const REPO = 'repository';
    private const REF = 'reference';
    private const TOKEN = 'token';
    private const TOKEN_USER = 'user';

    /**
     * @var array<string, string|null>
     */
    private $config;

    /**
     * @param array $config
     * @param array $env
     * @return GitHubConfig
     */
    public static function new(array $config, array $env = []): GitHubConfig
    {
        return new self($config, $env);
    }

    /**
     * @param array $config
     * @param array $env
     *
     * phpcs:disable Generic.Metrics.CyclomaticComplexity
     */
    private function __construct(array $config, array $env)
    {
        // phpcs:enable Generic.Metrics.CyclomaticComplexity
        $user = $config[self::TOKEN_USER]
            ?? Env::readEnv('GITHUB_USER_NAME', $env)
            ?? Env::readEnv('GITHUB_API_USER', $env)
            ?? Env::readEnv('GITHUB_ACTOR', $env)
            ?? null;
        $token = $config[self::TOKEN]
            ?? Env::readEnv('GITHUB_USER_TOKEN', $env)
            ?? Env::readEnv('GITHUB_API_TOKEN', $env)
            ?? Env::readEnv('GITHUB_TOKEN', $env)
            ?? null;
        $repo = $config[self::REPO]
            ?? Env::readEnv('GITHUB_API_REPOSITORY', $env)
            ?? Env::readEnv('GITHUB_REPOSITORY', $env)
            ?? null;
        $ref = $config[self::REF]
            ?? Env::readEnv('GITHUB_API_REPOSITORY_REF', $env)
            ?? Env::readEnv('GITHUB_REPOSITORY_REF', $env)
            ?? null;

        $this->config = [
            self::TOKEN => $token && is_string($token) ? $token : null,
            self::TOKEN_USER => $user && is_string($user) ? $user : null,
            self::REPO => $repo && is_string($repo) ? $repo : null,
            self::REF => $ref && is_string($ref) ? $ref : null,
        ];
    }

    /**
     * @return string|null
     */
    public function token(): ?string
    {
        return $this->config[self::TOKEN];
    }

    /**
     * @return string|null
     */
    public function user(): ?string
    {
        return $this->config[self::TOKEN_USER];
    }

    /**
     * @return string|null
     */
    public function repo(): ?string
    {
        return $this->config[self::REPO];
    }

    /**
     * @return string|null
     */
    public function ref(): ?string
    {
        return $this->config[self::REF];
    }

    /**
     * @return string|null
     */
    public function basicAuth(): ?string
    {
        $user = $this->user();
        $token = $this->token();

        if ($user && $token) {
            return 'Basic ' . base64_encode("{$user}:{$token}");
        }

        return null;
    }
}
