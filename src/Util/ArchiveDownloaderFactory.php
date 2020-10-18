<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Composer;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
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
     * @var \Composer\Config
     */
    private $config;

    /**
     * @var ProcessExecutor
     */
    private $process;

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
    public static function isValidType(string $type): bool
    {
        return in_array(strtolower($type), self::ARCHIVES, true);
    }

    /**
     * @param Io $io
     * @param Composer $composer
     * @param ProcessExecutor $executor
     * @param Filesystem $filesystem
     * @return ArchiveDownloaderFactory
     */
    public static function new(
        Io $io,
        Composer $composer,
        ProcessExecutor $executor,
        Filesystem $filesystem
    ): ArchiveDownloaderFactory {

        return new self($io, $composer, $executor, $filesystem);
    }

    /**
     * @param Io $io
     * @param Composer $composer
     * @param ProcessExecutor $executor
     * @param Filesystem $filesystem
     */
    private function __construct(
        Io $io,
        Composer $composer,
        ProcessExecutor $executor,
        Filesystem $filesystem
    ) {

        $this->io = $io;
        $this->config = $composer->getConfig();
        $this->downloadManager = $composer->getDownloadManager();
        if (is_callable([$composer, 'getLoop']) && class_exists(SyncHelper::class)) {
            $this->loop = $composer->getLoop();
        }
        $this->process = $executor;
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

        if (!static::isValidType($type)) {
            throw new \Exception(sprintf("Invalid archive type: '%s'.", $type));
        }

        $downloader = $this->downloadManager->getDownloader($type);

        $this->downloaders[$type] = $this->loop
            ? ArchiveDownloader::viaLoop($this->loop, $downloader, $this->io, $this->filesystem)
            : ArchiveDownloader::forV1($downloader, $this->io, $this->filesystem);

        return $this->downloaders[$type];
    }
}
