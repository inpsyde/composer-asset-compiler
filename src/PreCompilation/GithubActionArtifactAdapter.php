<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Package\Package;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Util\ArchiveDownloader;
use Inpsyde\AssetsCompiler\Util\ArchiveDownloaderFactory;
use Inpsyde\AssetsCompiler\Util\HttpClient;
use Inpsyde\AssetsCompiler\Util\Io;

class GithubActionArtifactAdapter implements Adapter
{
    /**
     * @var Io
     */
    private $io;

    /**
     * @var HttpClient
     */
    private $client;

    /**
     * @var ArchiveDownloaderFactory
     */
    private $downloaderFactory;

    /**
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $archiveDownloaderFactory
     * @return GithubActionArtifactAdapter
     */
    public static function new(
        Io $io,
        HttpClient $client,
        ArchiveDownloaderFactory $archiveDownloaderFactory
    ): GithubActionArtifactAdapter {

        return new self($io, $client, $archiveDownloaderFactory);
    }

    /**
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $archiveDownloaderFactory
     */
    private function __construct(
        Io $io,
        HttpClient $client,
        ArchiveDownloaderFactory $archiveDownloaderFactory
    ) {

        $this->io = $io;
        $this->client = $client;
        $this->downloaderFactory = $archiveDownloaderFactory;
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return 'gh-action-artifact';
    }

    /**
     * @param Asset $asset
     * @param string $hash
     * @param string $source
     * @param string $targetDir
     * @param array $config
     * @param array $environment
     * @return bool
     */
    public function tryPrecompiled(
        Asset $asset,
        string $hash,
        string $source,
        string $targetDir,
        array $config,
        array $environment
    ): bool {

        try {
            $ghConfig = GitHubConfig::new($config, $environment);
            [$endpoint, $owner] = $this->buildArtifactsEndpoint($source, $ghConfig);
            if (!$endpoint || !$owner) {
                $this->io->writeVerboseError('  Invalid configuration for GitHub artifact.');

                return false;
            }

            $distUrl = $this->retrieveArtifactUrl($source, $endpoint, $ghConfig);
            if (!$distUrl) {
                return false;
            }

            $auth = $ghConfig->basicAuth();
            $headers = $auth ? ["Authorization: {$auth}"] : [];

            $type = ArchiveDownloader::ZIP;
            $package = new Package($asset->name() . '-assets', 'artifact', 'artifact');
            $package->setDistType($type);
            $package->setDistUrl($distUrl);
            $package->setTargetDir($targetDir);
            $package->setTransportOptions(['http' => ['header' => $headers]]);

            return $this->downloaderFactory->create($type)->download($package, $targetDir);
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return false;
        }
    }

    /**
     * @param string $source
     * @param GitHubConfig $config
     * @return array{string,string}|array{null,null}
     */
    private function buildArtifactsEndpoint(string $source, GitHubConfig $config): array
    {
        if (!$source) {
            return [null, null];
        }

        $repo = $config->repo();
        $userRepo = $repo ? explode('/', $repo) : null;
        if (!$userRepo || count($userRepo) !== 2) {
            return [null, null];
        }

        $endpoint = "https://api.github.com/repos/{$repo}/actions/artifacts";

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return [null, null];
        }

        $safe = filter_var($endpoint, FILTER_SANITIZE_URL);
        $safe && is_string($safe) or $safe = null;

        return $safe ? [$safe, reset($userRepo) ?: ''] : [null, null];
    }

    /**
     * @param string $name
     * @param string $endpoint
     * @param GitHubConfig $config
     * @return string|null
     */
    private function retrieveArtifactUrl(
        string $name,
        string $endpoint,
        GitHubConfig $config
    ): ?string {

        $response = $this->client->get($endpoint, [], $config->basicAuth());
        $json = $response ? json_decode($response, true) : null;
        if (!$json || !is_array($json) || empty($json['artifacts'])) {
            throw new \Exception("Could not obtain a valid API response from {$endpoint}.");
        }

        /** @var string|null $artifactUrl */
        $artifactUrl = null;
        foreach ((array)$json['artifacts'] as $item) {
            $artifactUrl = is_array($item) ? $this->artifactUrl($item, $name) : null;
            if ($artifactUrl) {
                break;
            }
        }

        $repo = $config->repo() ?? '';
        $artifactUrl or $this->io->writeVerbose("  Artifact '{$name}' not found in '{$repo}'.");

        return $artifactUrl;
    }

    /**
     * @param array $data
     * @param string $targetName
     * @return string|null
     */
    private function artifactUrl(array $data, string $targetName): ?string
    {
        $name = $data['name'] ?? null;
        if (($name !== $targetName) || !empty($data['expired'])) {
            return null;
        }

        /** @var string|null $url */
        $url = $data['archive_download_url'] ?? null;
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }
}
