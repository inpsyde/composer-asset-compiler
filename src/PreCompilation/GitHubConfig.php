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
     * @var array
     */
    private $config;

    /**
     * @param array $config
     * @return GitHubConfig
     */
    public static function new(array $config): GitHubConfig
    {
        return new self($config);
    }

    /**
     * @param array $config
     */
    private function __construct(array $config)
    {
        $token = $config[self::TOKEN] ?? EnvResolver::readEnv('GITHUB_USER_TOKEN') ?? null;
        $user = $config[self::TOKEN_USER] ?? EnvResolver::readEnv('GITHUB_USER') ?? null;
        $repo = $config[self::REPO] ?? EnvResolver::readEnv('GITHUB_REPOSITORY') ?? null;

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
        $this->config[self::TOKEN];
    }

    /**
     * @return string|null
     */
    public function user(): ?string
    {
        $this->config[self::TOKEN_USER];
    }

    /**
     * @return string|null
     */
    public function repo(): ?string
    {
        $this->config[self::REPO];
    }
}
