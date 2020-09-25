<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Package\Package;
use Inpsyde\AssetsCompiler\Util\ArchiveDownloader;
use Inpsyde\AssetsCompiler\Util\ArchiveDownloaderFactory;
use Inpsyde\AssetsCompiler\Util\Io;

class ArchiveDownloaderAdapter implements Adapter
{
    /**
     * @var Io
     */
    private $io;

    /**
     * @var ArchiveDownloaderFactory
     */
    private $downloaderFactory;

    /**
     * @param Io $io
     * @param ArchiveDownloaderFactory $downloaderFactory
     * @return ArchiveDownloaderAdapter
     */
    public static function new(
        Io $io,
        ArchiveDownloaderFactory $downloaderFactory
    ): ArchiveDownloaderAdapter {

        return new self($io, $downloaderFactory);
    }

    /**
     * @param Io $io
     * @param ArchiveDownloaderFactory $downloaderFactory
     */
    private function __construct(
        Io $io,
        ArchiveDownloaderFactory $downloaderFactory
    ) {

        $this->io = $io;
        $this->downloaderFactory = $downloaderFactory;
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

        if (!ArchiveDownloaderFactory::isValidType($type ?? '')) {
            $typeName = is_string($type) ? $type : gettype($type);
            $message = ($type === null)
                ? "Could not determine archive type for {$source}."
                : "'{$typeName}' is not a valid archive type.";
            $this->io->writeVerboseError("  {$message}");

            return false;
        }

        /** @var string $type */

        $distUrl = $this->sanitizeAndMaybeAuthorizeSource($source, $config);
        if (!$distUrl) {
            return false;
        }

        try {
            $package = new Package($name, 'stable', 'stable');
            $package->setDistType($type);
            $package->setDistUrl($distUrl);
            $package->setTargetDir($targetDir);

            return $this->downloaderFactory->create($type)->download($package, $targetDir);
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
                return ArchiveDownloader::RAR;
            case 'tar':
                return ArchiveDownloader::TAR;
            case 'xz':
                return ArchiveDownloader::XZ;
        }

        return ArchiveDownloader::ZIP;
    }

    /**
     * @param string $source
     * @param array $config
     * @return string|null
     */
    private function sanitizeAndMaybeAuthorizeSource(string $source, array $config): ?string
    {
        if (!filter_var($source, FILTER_VALIDATE_URL)) {
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
                ?? $config['auth']['token']
                ?? '';
            is_string($pass) or $pass = '';
            $pass and $pass = rawurlencode($pass);
        }

        return $pass ? "{$schema}{$user}:{$pass}@{$url}" : "{$schema}{$user}@{$url}";
    }
}
