<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Inpsyde\AssetsCompiler\Util\ArchiveDownloader;
use Inpsyde\AssetsCompiler\Util\ArchiveDownloaderFactory;
use Inpsyde\AssetsCompiler\Util\HttpClient;
use Inpsyde\AssetsCompiler\Util\Io;

class GithubReleaseZipAdapter implements Adapter
{
    private const REPO = 'repository';
    private const TOKEN = 'token';
    private const TOKEN_USER = 'user';

    /**
     * @var Io
     */
    private $io;

    /**
     * @var ArchiveDownloaderAdapter
     */
    private $downloader;

    /**
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $archiveDownloaderFactory
     * @return GithubActionArtifactAdapter
     */
    public static function new(
        Io $io,
        ArchiveDownloaderFactory $archiveDownloaderFactory
    ): GithubReleaseZipAdapter {

        return new self($io, $archiveDownloaderFactory);
    }

    /**
     * @param Io $io
     * @param ArchiveDownloaderFactory $archiveDownloaderFactory
     */
    private function __construct(
        Io $io,
        ArchiveDownloaderFactory $archiveDownloaderFactory
    ) {

        $this->io = $io;
        $this->downloader = ArchiveDownloaderAdapter::new($io, $archiveDownloaderFactory);
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return 'gh-release-zip';
    }

    /**
     * @param string $name
     * @param string $hash
     * @param string $source
     * @param string $targetDir
     * @param array $config
     * @param string|null $version
     * @return bool
     */
    public function tryPrecompiled(
        string $name,
        string $hash,
        string $source,
        string $targetDir,
        array $config,
        ?string $version
    ): bool {

        try {
            if (!$version) {
                $this->io->writeVerboseError(
                    '  Invalid configuration for GitHub release zip.',
                    '  Assets version is required.'
                );

                return false;
            }

            $source = $this->buildSource($source, $config, $version);
            if (!$source) {
                $this->io->writeVerboseError('  Invalid configuration for GitHub release zip.');

                return false;
            }
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return false;
        }

        $config['type'] = ArchiveDownloader::ZIP;

        return $this->downloader->tryPrecompiled(
            $name,
            $hash,
            $source,
            $targetDir,
            $config,
            $version
        );
    }

    /**
     * @param string $source
     * @param array $config
     * @param string $version
     * @return string|null
     */
    private function buildSource(string $source, array $config, string $version): ?string
    {
        if (!$source) {
            return null;
        }

        $repo = $config[self::REPO] ?? null;
        $userRepo = ($repo && is_string($repo)) ? explode('/', $repo) : null;
        if (!$userRepo || count($userRepo) !== 2) {
            return null;
        }

        $user = $config[self::TOKEN_USER] ?? null;
        $token = $config[self::TOKEN] ?? null;
        if ($token && !$user) {
            $user = reset($userRepo);
        }

        $auth = ($user && $token) ? "{$user}:{$token}@" : '';

        $source = "https://{$auth}github.com/{$repo}/releases/download/{$version}/{$source}.zip";
        if (!filter_var($source, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $source;
    }
}
