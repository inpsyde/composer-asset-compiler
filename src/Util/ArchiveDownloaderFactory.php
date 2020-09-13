<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Composer;
use Composer\Downloader\RarDownloader;
use Composer\Downloader\TarDownloader;
use Composer\Downloader\XzDownloader;
use Composer\Downloader\ZipDownloader;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;

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
     * @var RemoteFilesystem
     */
    private $downloader;

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
        $this->process = $executor;
        $this->filesystem = $filesystem;
        $this->downloader = \Composer\Factory::createRemoteFilesystem(
            $io->composerIo(),
            $this->config
        );
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

        $io = $this->io->composerIo();
        $config =  $this->config;
        $params = [$io, $config, null, null, $this->process, $this->downloader, $this->filesystem];

        switch (strtolower($type)) {
            case ArchiveDownloader::RAR:
                $downloader = new RarDownloader(...$params);
                break;
            case ArchiveDownloader::TAR:
                $downloader = new TarDownloader(...$params);
                break;
            case ArchiveDownloader::XZ:
                $downloader = new XzDownloader(...$params);
                break;
            case ArchiveDownloader::ZIP:
            default:
                $downloader = new ZipDownloader(...$params);
                break;
        }

        $this->downloaders[$type] = ArchiveDownloader::new(
            $downloader,
            $this->io,
            $this->filesystem
        );

        return $this->downloaders[$type];
    }
}
