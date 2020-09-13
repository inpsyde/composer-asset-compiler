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
            $this->downloader->download($package, $path, false);

            return true;
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return false;
        }
    }
}
