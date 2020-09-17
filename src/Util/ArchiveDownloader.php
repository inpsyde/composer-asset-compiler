<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Downloader\ArchiveDownloader as ComposerArchiveDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

class ArchiveDownloader
{
    public const ZIP = 'zip';
    public const RAR = 'rar';
    public const XZ = 'xz'; // phpcs:ignore
    public const TAR = 'tar';

    /**
     * @var ComposerArchiveDownloader
     */
    private $downloader;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param ComposerArchiveDownloader $downloader
     * @param Io $io
     * @param Filesystem $filesystem
     * @return ArchiveDownloader
     */
    public static function new(
        ComposerArchiveDownloader $downloader,
        Io $io,
        Filesystem $filesystem
    ): ArchiveDownloader {

        return new self($downloader, $io, $filesystem);
    }

    /**
     * @param ComposerArchiveDownloader $downloader
     * @param Io $io
     * @param bool $ver2
     */
    private function __construct(
        ComposerArchiveDownloader $downloader,
        Io $io,
        Filesystem $filesystem
    ) {

        $this->downloader = $downloader;
        $this->io = $io;
        $this->filesystem = $filesystem;
    }

    /**
     * @param PackageInterface $package
     * @param string $path
     * @return bool
     */
    public function download(PackageInterface $package, string $path): bool
    {
        try {
            $tempDir = dirname($path) . '/.tmp' . substr(md5(uniqid($path, true)), 0, 8);
            $this->filesystem->ensureDirectoryExists($tempDir);
            $this->downloader->download($package, $tempDir, false);
            $this->filesystem->ensureDirectoryExists($path);
            foreach (glob("{$tempDir}/*") as $item) {
                if (!is_dir($item) && !is_file($item)) {
                    continue;
                }

                $itemPath = $this->filesystem->normalizePath("{$path}/" . basename($item));
                if (is_dir($itemPath) || is_file($item)) {
                    $this->filesystem->remove($itemPath);
                }

                $this->filesystem->copy($item, $itemPath);
            }

            return true;
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return false;
        } finally {
            if (isset($tempDir)) {
                $this->filesystem->removeDirectory($tempDir);
            }
        }
    }
}
