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
    private const PATTERN_REGEX = '~^(.+?)/([^/]+\.(?:[a-z0-9]{1,4}(?:\.[a-z0-9]{1,4})?|\*))$~i';
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
     * @param Filesystem $filesystem
     * @param Io $io
     * @return HashBuilder
     */
    public static function new(Filesystem $filesystem, Io $io): HashBuilder
    {
        return new static($filesystem, $io);
    }

    /**
     * @param Filesystem $filesystem
     * @param Io $io
     */
    private function __construct(Filesystem $filesystem, Io $io)
    {
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
            if (isset($done[$file])) {
                continue;
            }
            $done[$file] = 1;
            if (file_exists($file) && is_readable($file)) {
                $hashes .= @(md5_file($file) ?: '');
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

        /** @var FileFinder|null $finder */
        $finder = null;
        foreach ($patterns as $pattern) {
            try {
                $pathfinder = FileFinder::create()->ignoreUnreadableDirs(true)->ignoreVCS(true)->sortByName();
                $hasFile = preg_match(self::PATTERN_REGEX, $pattern, $matches);
                $dir = "{$basePath}/" . ltrim($hasFile ? $matches[1] : $pattern, './');
                $hasFile
                    ? $pathfinder->in("{$dir}/")->name($matches[2])
                    : $pathfinder->in($dir);
                $finder = $finder ? $finder->append($pathfinder) : $pathfinder;
            } catch (\Throwable $throwable) {
                $this->io->writeError($throwable->getMessage());
                continue;
            }
        }

        if (!$finder) {
            $patternsStr = implode("', '", $patterns);
            $this->io->writeError("Error building Symfony Finder for '{$patternsStr}'.");

            return $files;
        }

        foreach ($finder->files() as $fileInfo) {
            $path = $fileInfo->getRealPath();
            if ($path) {
                $rel = $fileInfo->getRelativePath()  . '/' . $fileInfo->getBasename();
                $normalized = $this->filesystem->normalizePath($rel);
                $this->io->writeVerbose("Will use '{$normalized}' file to calculate package hash");
                $files[] = $this->filesystem->normalizePath($path);
            }
        }

        return $files;
    }
}
