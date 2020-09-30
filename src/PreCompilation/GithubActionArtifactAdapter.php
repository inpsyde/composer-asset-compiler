<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Package\Package;
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
            $ghConfig = GitHubConfig::new($config);

            [$endpoint, $owner] = $this->buildEndpoint($source, $ghConfig);
            if (!$endpoint || !$owner) {
                $this->io->writeVerboseError('  Invalid configuration for GitHub artifact.');

                return false;
            }

            $distUrl = $this->retrieveArchiveUrl($source, $endpoint, $owner, $ghConfig);

            $type = ArchiveDownloader::ZIP;
            $package = new Package($name, 'stable', 'stable');
            $package->setDistType($type);
            $package->setDistUrl($distUrl);
            $package->setTargetDir($targetDir);
            $this->downloaderFactory->create($type)->download($package, $targetDir);

            return true;
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
    private function buildEndpoint(string $source, GitHubConfig $config): array
    {
        if (!$source) {
            return [null, null];
        }

        $repo = $config->repo();
        $userRepo = ($repo && is_string($repo)) ? explode('/', $repo) : null;
        if (!$userRepo || count($userRepo) !== 2) {
            return [null, null];
        }

        $endpoint = "https://api.github.com/repos/{$repo}/actions/artifacts";

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return [null, null];
        }

        $safe = filter_var($endpoint, FILTER_SANITIZE_URL);
        $safe && is_string($safe) or $safe = null;

        return $safe ? [$safe, reset($userRepo) ?: null] : [null, null];
    }

    /**
     * @param string $artifactName
     * @param string $endpoint
     * @param string $owner
     * @param GitHubConfig $config
     * @return string
     */
    private function retrieveArchiveUrl(
        string $artifactName,
        string $endpoint,
        string $owner,
        GitHubConfig $config
    ): string {

        $token = $config->token();
        $repo = $config->repo() ?? '';
        $authString = '';
        if ($token) {
            $user = $config->user() ?? $owner;
            $authString = "https://{$user}:{$token}@";
            $endpoint = (string)preg_replace('~^https://(.+)~', $authString . '$1', $endpoint);
        }

        $response = $this->client->get($endpoint);
        $json = $response ? json_decode($response, true) : null;
        if (!$json || !is_array($json) || empty($json['artifacts'])) {
            throw new \Exception("Could not obtain a valid API response from {$endpoint}.");
        }

        $archiveUrl = null;
        foreach ((array)$json['artifacts'] as $artifactData) {
            $name = is_array($artifactData) ? $artifactData['name'] : null;
            $url = $name ? ($artifactData['archive_download_url'] ?? null) : null;
            if (($name === $artifactName) && $url && filter_var($url, FILTER_VALIDATE_URL)) {
                $archiveUrl = $url;
                break;
            }
        }

        if (!$archiveUrl) {
            $this->io->writeVerbose("  Artifact '{$artifactName}' not found in '{$repo}'.");

            return '';
        }

        /** @var string $archiveUrl */

        if ($authString) {
            $archiveUrl = preg_replace('~^https://(.+)~', $authString . '$1', $archiveUrl);
        }

        return (string)$archiveUrl;
    }
}
