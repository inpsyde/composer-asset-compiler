<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Downloader\DownloaderInterface;
use Composer\Downloader\DownloadManager;
use Composer\Downloader\FileDownloader;
use Composer\IO\ConsoleIO;
use Composer\Util\Filesystem;
use Composer\Util\Loop;

class ArchiveDownloaderFactory
{
    private const ARCHIVES = [
        ArchiveDownloader::ZIP,
        ArchiveDownloader::RAR,
        ArchiveDownloader::XZ,
        ArchiveDownloader::TAR,
    ];

    /** @var array<string, ArchiveDownloader> */
    private array $downloaders = [];

    /**
     * @param string $type
     * @return bool
     */
    public static function isValidArchiveType(string $type): bool
    {
        return in_array(strtolower($type), self::ARCHIVES, true);
    }

    /**
     * @param Loop $loop
     * @param DownloadManager $downloadManager
     * @param Filesystem $filesystem
     * @param Io $io
     * @return ArchiveDownloaderFactory
     */
    public static function new(
        Loop $loop,
        DownloadManager $downloadManager,
        Filesystem $filesystem,
        Io $io,
    ): ArchiveDownloaderFactory {

        return new self($loop, $downloadManager, $filesystem, $io);
    }

    /**
     * @param Loop $loop
     * @param DownloadManager $downloadManager
     * @param Filesystem $filesystem
     * @param Io $io
     */
    private function __construct(
        private Loop $loop,
        private DownloadManager $downloadManager,
        private Filesystem $filesystem,
        private Io $io
    ) {
    }

    /**
     * @param string $type
     * @return ArchiveDownloader
     */
    public function create(string $type): ArchiveDownloader
    {
        if (isset($this->downloaders[$type])) {
            return $this->downloaders[$type];
        }

        if (!static::isValidArchiveType($type)) {
            throw new \Exception(sprintf("Invalid archive type: '%s'.", $type));
        }

        $this->downloaders[$type] = ArchiveDownloader::new(
            $this->loop,
            $this->factoryDownloader($type),
            $this->io,
            $this->filesystem
        );

        return $this->downloaders[$type];
    }

    /**
     * @param string $type
     * @return DownloaderInterface
     */
    private function factoryDownloader(string $type): DownloaderInterface
    {
        $downloader = $this->downloadManager->getDownloader($type);
        if (!($downloader instanceof FileDownloader) || $this->io->isVeryVerbose()) {
            return $downloader;
        }

        // When it's not very verbose we silence FileDownloader ConsoleIO

        $ref = new \ReflectionClass($downloader);
        $prop = $ref->getProperty('io');
        $prop->setAccessible(true);
        $io = $prop->getValue($downloader);
        if ($io instanceof ConsoleIO) {
            $prop->setValue($downloader, SilentConsoleIo::new($io));
        }

        return $downloader;
    }
}
