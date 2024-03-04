<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Util\Io;
use Symfony\Component\Finder\Finder as SymfonyFinder;
use Symfony\Component\Finder\SplFileInfo;

class PathsFinder
{
    private const PATTERN_REGEX = '~^(.+?)/([^/]+)$~';

    private const DEFAULT_FILES = [
        '/package.json',
        '/package-lock.json',
        '/npm-shrinkwrap.json',
        '/yarn.lock',
    ];

    /** @var array<string, list<non-empty-string>> */
    private array $cache = [];

    /**
     * @param \Iterator<Asset> $assets
     * @param Filesystem $filesystem
     * @param Io $io
     * @param string $cwd
     * @return static
     */
    public static function new(
        \Iterator $assets,
        Filesystem $filesystem,
        Io $io,
        string $cwd
    ): static {

        return new static($assets, $filesystem, $io, $cwd);
    }

    /**
     * @param \Iterator<Asset> $assets
     * @param Filesystem $filesystem
     * @param Io $io,
     * @param string $cwd
     */
    final private function __construct(
        private \Iterator $assets,
        private Filesystem $filesystem,
        private Io $io,
        private string $cwd
    ) {
    }

    /**
     * @param Asset $asset
     * @return list<non-empty-string>
     */
    public function findAssetPaths(Asset $asset): array
    {
        $key = $asset->name();
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $basePath = ($asset->isValid() ? $asset->path() : null) ?? '';
        if ($basePath === '') {
            $this->cache[$key] = [];

            return [];
        }

        /** @var list<non-empty-string> $files */
        $files = [];
        foreach (self::DEFAULT_FILES as $defaultFilePath) {
            $files[] = $this->relativePath($basePath . $defaultFilePath);
        }

        $finder = $this->createFinder($asset);

        if ($finder === null) {
            return $files;
        }

        foreach ($finder->files() as $fileInfo) {
            /** @var non-empty-string $path */
            $fullpath = $this->normalizePath($fileInfo);
            if ($fullpath !== null) {
                $files[] = $this->relativePath($fullpath);
            }
        }

        return $files;
    }

    /**
     * @return list<non-empty-string>
     */
    public function findAllAssetsPaths(): array
    {
        $found = [];
        foreach ($this->assets as $asset) {
            $assetPaths = $this->findAssetPaths($asset);
            if ($assetPaths !== []) {
                $found = array_merge($found, $assetPaths);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param SplFileInfo $fileInfo
     * @return non-empty-string|null
     */
    private function normalizePath(SplFileInfo $fileInfo): ?string
    {
        $path = $fileInfo->getRealPath();
        if ($path === false) {
            return null;
        }
        $rel = $fileInfo->getRelativePath() . '/' . $fileInfo->getBasename();
        /** @var non-empty-string */
        return $this->filesystem->normalizePath($rel);
    }

    /**
     * @param Asset $asset
     * @return SymfonyFinder|null
     */
    private function createFinder(Asset $asset): ?SymfonyFinder
    {
        $patterns = $asset->srcPaths();
        if (!$patterns) {
            return null;
        }

        /** @var SymfonyFinder|null $finder */
        $finder = null;
        $basePath = $asset->path();
        foreach ($patterns as $pattern) {
            try {
                $itemFinder = SymfonyFinder::create()
                    ->ignoreUnreadableDirs(true)
                    ->ignoreVCS(true)
                    ->sortByName();
                $hasFile = preg_match(self::PATTERN_REGEX, $pattern, $matches);
                $dir = "{$basePath}/" . ltrim($hasFile ? $matches[1] : $pattern, './');
                $hasFile
                    ? $itemFinder->in("{$dir}/")->name($matches[2])
                    : $itemFinder->in($dir);
                $finder = $finder?->append($itemFinder) ?? $itemFinder;
            } catch (\Throwable $throwable) {
                $this->io->writeError($throwable->getMessage());
                continue;
            }
        }

        if ($finder === null) {
            $patternsStr = implode("', '", $patterns);
            $this->io->writeError("Error building Symfony Finder for '{$patternsStr}'.");
        }

        return $finder;
    }

    /**
     * @param non-empty-string $fullpath
     * @return non-empty-string
     */
    private function relativePath(string $fullpath): string
    {
        if (!$this->filesystem->isAbsolutePath($fullpath)) {
            return $fullpath;
        }

        $relative = $this->filesystem->findShortestPath($this->cwd, $fullpath);

        return str_starts_with($relative, '.') ? $relative : "./{$relative}";
    }
}
