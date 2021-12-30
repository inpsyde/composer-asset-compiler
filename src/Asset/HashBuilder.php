<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Util\Env;
use Inpsyde\AssetsCompiler\Util\Io;
use Symfony\Component\Finder\Finder as FileFinder;

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
     * @var Filesystem
     */
    private $filesystem;
    /**
     * @var Io
     */
    private $io;

    /**
     * @param array $environment
     * @param Filesystem $filesystem
     * @param Io $io
     * @return HashBuilder
     */
    public static function new(array $environment, Filesystem $filesystem, Io $io): HashBuilder
    {
        return new static($environment, $filesystem, $io);
    }

    /**
     * @param array $environment
     * @param Filesystem $filesystem
     * @param Io $io
     */
    private function __construct(array $environment, Filesystem $filesystem, Io $io)
    {
        $this->environment = $environment;
        $this->filesystem = $filesystem;
        $this->io = $io;
    }

    /**
     * @param Asset $asset
     * @return string|null
     */
    public function forAsset(Asset $asset): ?string
    {
        $basePath = $asset->isValid() ? $asset->path() : null;
        if (!$basePath) {
            return null;
        }

        $key = $asset->name();
        if ($this->hashes[$key] ?? null) {
            return $this->hashes[$key];
        }

        $files = $this->mergeFilesInPatterns(
            $asset,
            $basePath,
            [
                $basePath . '/package.json',
                $basePath . '/package-lock.json',
                $basePath . '/npm-shrinkwrap.json',
                $basePath . '/yarn.lock',
            ]
        );

        $hashes = '';
        $done = [];
        foreach ($files as $file) {
            $normalized = $this->filesystem->normalizePath($file);
            if (isset($done[$normalized])) {
                continue;
            }
            $done[$normalized] = 1;
            if (file_exists($normalized) && is_readable($normalized)) {
                $hashes .= @(md5_file($normalized) ?: '');
            }
        }

        $hashes .= $asset->isInstall() ? '|install|' : '|update|';
        $hashes .= Env::replaceEnvVariables(implode(' ', $asset->script()), $asset->env());

        $this->hashes[$key] = sha1($hashes);

        return $this->hashes[$key];
    }

    /**
     * @param Asset $asset
     * @param string $basePath
     * @param list<string> $files
     * @return list<string>
     */
    private function mergeFilesInPatterns(Asset $asset, string $basePath, array $files): array
    {
        $patterns = $asset->srcPaths();
        if (!$patterns) {
            return $files;
        }

        $finder = FileFinder::create()->ignoreUnreadableDirs(true)->ignoreVCS(true);
        foreach ($patterns as $pattern) {
            try {
                $finder = $finder->in($basePath . '/' . ltrim($pattern, '/'));
            } catch (\Throwable $throwable) {
                $this->io->writeVerboseError(
                    "Could not use '{$pattern}' to create package hash",
                    $throwable->getMessage()
                );

                continue;
            }
        }

        /** @var \Symfony\Component\Finder\SplFileInfo $fileInfo */
        foreach ($finder->files() as $fileInfo) {
            $path = $fileInfo->getRealPath();
            $path and $files[] = $path;
        }

        return $files;
    }
}
