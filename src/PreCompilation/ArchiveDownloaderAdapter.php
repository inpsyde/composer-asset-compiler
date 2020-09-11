<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Downloader\ArchiveDownloader;
use Composer\Downloader\RarDownloader;
use Composer\Downloader\TarDownloader;
use Composer\Downloader\XzDownloader;
use Composer\Downloader\ZipDownloader;
use Composer\Package\Package;
use Inpsyde\AssetsCompiler\Util\Io;

class ArchiveDownloaderAdapter implements Adapter
{
    private const ZIP = 'zip';
    private const RAR = 'rar';
    private const XZ = 'xz'; // phpcs:ignore
    private const TAR = 'tar';
    private const ARCHIVES = [
        self::ZIP,
        self::RAR,
        self::XZ,
        self::TAR,
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
     * @param Io $io
     * @param \Composer\Config $config
     * @return ArchiveDownloaderAdapter
     */
    public static function new(Io $io, \Composer\Config $config): ArchiveDownloaderAdapter
    {
        return new static($io, $config);
    }

    /**
     * @param Io $io
     * @param \Composer\Config $config
     */
    final private function __construct(Io $io, \Composer\Config $config)
    {
        $this->io = $io;
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return 'archive';
    }

    /**
     * @param string $source
     * @param string $targetDir
     * @param array $config
     * @return bool
     */
    public function tryPrecompiled(
        string $name,
        string $hash,
        string $source,
        string $targetDir,
        array $config
    ): bool {

        $type = $this->determineType($config, $source);
        if (!$type || !in_array($type, self::ARCHIVES, true)) {
            $typeName = is_string($type) ? $type : gettype($type);
            $message = ($type === null)
                ? "Could not determine archive type for {$source}."
                : "{$typeName} is not a valid archive type.";
            $this->io->writeVerboseError("  {$message}");

            return false;
        }

        $distUrl = $this->sanitizeAndMaybeAuthorizeSource($source, $config);
        if (!$distUrl) {
            return false;
        }

        try {
            $package = new Package($name, 'stable', 'stable');
            $package->setDistType($type);
            $package->setDistUrl($distUrl);
            $package->setTargetDir($targetDir);

            $this->factoryDownloader($type)->download($package, $targetDir, false);

            return true;
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return false;
        }
    }

    /**
     * @param array $config
     * @param string $source
     * @return string|null
     */
    private function determineType(array $config, string $source): ?string
    {
        $type = $config['type'] ?? null;
        if ($type !== null) {
            return is_string($type) ? strtolower($type) : null;
        }

        switch (strtolower((string)(pathinfo($source, PATHINFO_EXTENSION) ?: ''))) {
            case 'rar':
                return self::RAR;
            case 'tar':
                return self::TAR;
            case 'xz':
                return self::XZ;
        }

        return self::ZIP;
    }

    /**
     * @param string $source
     * @param array $config
     * @return string|null
     */
    private function sanitizeAndMaybeAuthorizeSource(string $source, array $config): ?string
    {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $this->io->writeVerboseError("  '{$source}' is not a valid URL.");

            return null;
        }

        $safeSource = filter_var($source, FILTER_SANITIZE_URL);
        if (!$safeSource) {
            $this->io->writeVerboseError("  '{$source}' is not a valid URL.");

            return null;
        }

        /** @var string $safeSource */

        preg_match('~^(https?://)(?:([^:]+)(?::([^@]+))?@)?(.+)~i', $safeSource, $matches);
        $schema = $matches[1] ?? null;
        $url = $matches[4] ?? null;

        if (!$schema || !$url) {
            $this->io->writeVerboseError("  '{$source}' is not a valid URL.");

            return null;
        }

        $auth = $config['auth'] ?? null;
        if (!$auth || !is_array($auth)) {
            return $safeSource;
        }

        $user = $matches[2] ?? null;
        $pass = $matches[3] ?? null;
        if ($user && $pass) {
            return $safeSource;
        }

        if (!$user) {
            $user = $auth['user'] ?? $auth['usr'] ?? null;
            if (!$user || !is_string($user)) {
                return $safeSource;
            }

            $user = rawurlencode($user);
        }

        if (!$pass) {
            $pass = $config['auth']['password']
                ?? $config['auth']['pass']
                ?? $config['auth']['secret']
                ?? $config['auth']['pwd']
                ?? '';
            is_string($pass) or $pass = '';
            $pass and $pass = rawurlencode($pass);
        }

        return $pass ? "{$schema}{$user}:{$pass}@{$url}" : "{$schema}{$user}@{$url}";
    }

    /**
     * @param string $type
     * @return ArchiveDownloader
     */
    private function factoryDownloader(string $type): ArchiveDownloader
    {
        if (!empty($this->downloaders[$type])) {
            return $this->downloaders[$type];
        }

        switch ($type) {
            case self::RAR:
                $downloader = new RarDownloader($this->io->composerIo(), $this->config);
                break;
            case self::TAR:
                $downloader = new TarDownloader($this->io->composerIo(), $this->config);
                break;
            case self::XZ:
                $downloader = new XzDownloader($this->io->composerIo(), $this->config);
                break;
            case self::ZIP:
            default:
                $downloader = new ZipDownloader($this->io->composerIo(), $this->config);
                break;
        }

        $this->downloaders[$type] = $downloader;

        return $downloader;
    }
}
