<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Inpsyde\AssetsCompiler\Util\EnvResolver;

final class HashBuilder
{
    /**
     * @var array
     */
    private $environment;

    /**
     * @var array<string, string>
     */
    private $hashes = [];

    /**
     * @param array $environment
     * @return HashBuilder
     */
    public static function new(array $environment): HashBuilder
    {
        return new static($environment);
    }

    /**
     * @param string $envType
     * @param array $environment
     */
    private function __construct(array $environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param Asset $asset
     * @param string|null $seed
     * @return string|null
     */
    public function forAsset(Asset $asset, string $seed = null): ?string
    {
        $basePath = $asset->isValid() ? $asset->path() : null;
        if (!$basePath) {
            return null;
        }

        $key = $asset->name();
        if ($this->hashes[$key] ?? null) {
            return $this->hashes[$key];
        }

        $files = [
            '/package.json',
            '/package-lock.json',
            '/npm-shrinkwrap.json',
            '/yarn.lock',
        ];

        $hashes = '';
        foreach ($files as $file) {
            if (file_exists($basePath . $file) && is_readable($basePath . $file)) {
                $hashes .= @(md5_file($basePath . $file) ?: ''); // phpcs:ignore
            }
        }

        $data = [
            $hashes,
            EnvResolver::replaceEnvVariables(
                implode(' ', $asset->script()),
                array_merge(array_filter($this->environment), array_filter($asset->env()))
            ),
        ];

        $seed and $data[] = $seed;

        $this->hashes[$key] = sha1(serialize($data));

        return $this->hashes[$key];
    }
}
