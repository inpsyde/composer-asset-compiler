<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Downloader\DownloaderInterface;
use Composer\Downloader\FileDownloader;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Loop;
use Composer\Util\SyncHelper;
use Symfony\Component\Finder\Finder;

class ArchiveDownloader
{
    public const ZIP = 'zip';
    public const RAR = 'rar';
    public const XZ = 'xz'; // phpcs:ignore
    public const TAR = 'tar';

    /**
     * @var callable(PackageInterface,string):void
     */
    private $downloadCallback;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Loop $loop
     * @param DownloaderInterface $downloader
     * @param Io $io
     * @param Filesystem $filesystem
     * @return ArchiveDownloader
     */
    public static function viaLoop(
        Loop $loop,
        DownloaderInterface $downloader,
        Io $io,
        Filesystem $filesystem
    ): ArchiveDownloader {

        $downloadCallback = static function (
            PackageInterface $package,
            string $path
        ) use (
            $loop,
            $downloader
        ): void {

            SyncHelper::downloadAndInstallPackageSync($loop, $downloader, $path, $package);
        };

        return new self($downloadCallback, $io, $filesystem);
    }

    /**
     * @param DownloaderInterface $downloader
     * @param Io $io
     * @param Filesystem $filesystem
     * @return ArchiveDownloader
     */
    public static function forV1(
        DownloaderInterface $downloader,
        Io $io,
        Filesystem $filesystem
    ): ArchiveDownloader {

        $downloadCallback = static function (
            PackageInterface $package,
            string $path
        ) use ($downloader): void {
            /** @psalm-suppress PossiblyFalseArgument */
            ($downloader instanceof FileDownloader)
                ? $downloader->download($package, $path, false)
                : $downloader->download($package, $path);
        };

        return new self($downloadCallback, $io, $filesystem);
    }

    /**
     * @param callable(PackageInterface,string):void $downloadCallback
     * @param Io $io
     * @param Filesystem $filesystem
     */
    private function __construct(
        callable $downloadCallback,
        Io $io,
        Filesystem $filesystem
    ) {

        $this->downloadCallback = $downloadCallback;
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
            ($this->downloadCallback)($package, $tempDir);
            $this->filesystem->ensureDirectoryExists($path);

            $finder = new Finder();
            $finder->in($tempDir)->ignoreVCS(true)->ignoreUnreadableDirs()->depth('== 0');

            $errors = 0;
            /** @var \Symfony\Component\Finder\SplFileInfo $item */
            foreach ($finder as $item) {
                $basename = $item->getBasename();
                $targetPath = $this->filesystem->normalizePath("{$path}/{$basename}");
                if (is_dir($targetPath) || is_file($targetPath)) {
                    $this->filesystem->remove($targetPath);
                }

                $this->filesystem->copy($item->getPathname(), $targetPath) or $errors++;
            }

            return $errors === 0;
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
