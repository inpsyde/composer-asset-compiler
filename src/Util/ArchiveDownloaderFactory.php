<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Composer;
use Composer\Downloader\DownloaderInterface;
use Composer\Downloader\FileDownloader;
use Composer\IO\ConsoleIO;
use Composer\Util\Filesystem;
use Composer\Util\SyncHelper;

class ArchiveDownloaderFactory
{
    private const ARCHIVES = [
        ArchiveDownloader::ZIP,
        ArchiveDownloader::RAR,
        ArchiveDownloader::XZ,
        ArchiveDownloader::TAR,
    ];

    /**
     * @var array<string, ArchiveDownloader>
     */
    private $downloaders = [];

    /**
     * @var Io
     */
    private $io;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var \Composer\Downloader\DownloadManager
     */
    private $downloadManager;

    /**
     * @var \Composer\Util\Loop|null
     */
    private $loop;

    /**
     * @param string $type
     * @return bool
     */
    public static function isValidArchiveType(string $type): bool
    {
        return in_array(strtolower($type), self::ARCHIVES, true);
    }

    /**
     * @param Io $io
     * @param Composer $composer
     * @param Filesystem $filesystem
     * @return ArchiveDownloaderFactory
     */
    public static function new(
        Io $io,
        Composer $composer,
        Filesystem $filesystem
    ): ArchiveDownloaderFactory {

        return new self($io, $composer, $filesystem);
    }

    /**
     * @param Io $io
     * @param Composer $composer
     * @param Filesystem $filesystem
     */
    private function __construct(
        Io $io,
        Composer $composer,
        Filesystem $filesystem
    ) {

        $this->io = $io;
        $this->downloadManager = $composer->getDownloadManager();
        /** @psalm-suppress RedundantCondition */
        if (is_callable([$composer, 'getLoop']) && class_exists(SyncHelper::class)) {
            $this->loop = $composer->getLoop();
        }
        $this->filesystem = $filesystem;
    }

    /**
     * @param string $type
     * @return ArchiveDownloader
     */
    public function create(string $type): ArchiveDownloader
    {
        if (!empty($this->downloaders[$type])) {
            return $this->downloaders[$type];
        }

        if (!static::isValidArchiveType($type)) {
            throw new \Exception(sprintf("Invalid archive type: '%s'.", $type));
        }

        $downloader = $this->factoryDownloader($type);

        $this->downloaders[$type] = $this->loop
            ? ArchiveDownloader::viaLoop($this->loop, $downloader, $this->io, $this->filesystem)
            : ArchiveDownloader::forV1($downloader, $this->io, $this->filesystem);

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
