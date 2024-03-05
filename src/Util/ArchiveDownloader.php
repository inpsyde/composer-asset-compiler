<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Downloader\DownloaderInterface;
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
            $downloader->download($package, $path);
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
            $distUrl = $package->getDistUrl();

            // Download callback makes use of Composer downloader, and will empty the target path.
            // When target does not exist, that's irrelevant and we can unpack directly there.
            if (!file_exists($path)) {
                $this->filesystem->ensureDirectoryExists($path);
                $this->io->writeVerbose(
                    "Downloading and unpack '{$distUrl}' in new directory '{$path}'..."
                );
                ($this->downloadCallback)($package, $path);

                return true;
            }

            if (!is_dir($path)) {
                throw new \Error("Could not use '{$path}' as target for unpacking '{$distUrl}'.");
            }

            /**
             * If here, target path is an existing directory. We can't use download callback to
             * download there, or Composer will delete every existing file in it.
             * So we first unpack in a temporary folder and then move unpacked files from the temp
             * dir to final target dir. That's surely slower, but necessary.
             * @psalm-suppress RedundantCastGivenDocblockType
             */
            $hash = (string) substr(md5(uniqid($path, true)), 0, 8);
            $tempDir = dirname($path) . '/.tmp' . $hash;
            $this->io->writeVerbose(
                "Archive target path '{$path}' is an existing directory.",
                "Downloading and unpacking '{$distUrl}' in the temporary folder '{$tempDir}'..."
            );
            $this->filesystem->ensureDirectoryExists($tempDir);
            ($this->downloadCallback)($package, $tempDir);
            $this->filesystem->ensureDirectoryExists($path);

            $finder = Finder::create()->in($tempDir)->ignoreVCS(true)->files();

            $this->io->writeVerbose(
                "Copying unpacked files from temporary folder '{$tempDir}' to '{$path}'..."
            );

            $errors = 0;
            foreach ($finder as $item) {
                $relative = $item->getRelativePathname();
                $targetPath = $this->filesystem->normalizePath("{$path}/{$relative}");
                $this->filesystem->ensureDirectoryExists(dirname($targetPath));
                $sourcePath = $item->getPathname();
                if (file_exists($targetPath)) {
                    $this->io->writeVeryVerbose("   - removing existing '{$targetPath}'...");
                    $this->filesystem->remove($targetPath);
                }
                $this->io->writeVeryVerbose("   - moving '{$sourcePath}' to '{$targetPath}'...");
                $this->filesystem->copy($sourcePath, $targetPath) or $errors++;
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
