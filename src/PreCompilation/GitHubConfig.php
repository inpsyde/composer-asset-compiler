<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Inpsyde\AssetsCompiler\Util\EnvResolver;

class GitHubConfig
{
    private const REPO = 'repository';
    private const TOKEN = 'token';
    private const TOKEN_USER = 'user';

    /**
     * @var array<string, string|null>
     */
    private $config;

    /**
     * @param array $config
     * @return GitHubConfig
     */
    public static function new(array $config, array $env = []): GitHubConfig
    {
        return new self($config, $env);
    }

    /**
     * @param array $config
     */
    private function __construct(array $config, array $env)
    {
        $user = $config[self::TOKEN_USER]
            ?? EnvResolver::readEnv('GITHUB_USER_NAME', $env)
            ?? EnvResolver::readEnv('GITHUB_ACTOR', $env)
            ?? null;
        $token = $config[self::TOKEN]
            ?? EnvResolver::readEnv('GITHUB_API_TOKEN', $env)
            ?? EnvResolver::readEnv('GITHUB_TOKEN', $env)
            ?? null;
        $repo = $config[self::REPO]
            ?? EnvResolver::readEnv('GITHUB_API_REPOSITORY', $env)
            ?? EnvResolver::readEnv('GITHUB_REPOSITORY', $env)
            ?? null;

        $this->config = [
            self::TOKEN => $token && is_string($token) ? $token : null,
            self::TOKEN_USER => $user && is_string($user) ? $user : null,
            self::REPO => $repo && is_string($repo) ? $repo : null,
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
}
