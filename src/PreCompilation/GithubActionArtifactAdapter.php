<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $downloaderFactory
     * @return GithubActionArtifactAdapter
     */
    public static function new(
        Io $io,
        HttpClient $client,
        ArchiveDownloaderFactory $downloaderFactory
    ): GithubActionArtifactAdapter {

        return new self($io, $client, $downloaderFactory);
    }

    /**
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $downloaderFactory
     */
    private function __construct(
        private Io $io,
        private HttpClient $client,
        private ArchiveDownloaderFactory $downloaderFactory
    ) {
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
            $endpoint ??= '';
            $owner ??= '';
            if (($endpoint === '') || ($owner === '')) {
                $this->io->writeError('  Invalid configuration for GitHub artifact.');

                return false;
            }

            $distUrl = $this->retrieveArtifactUrl($source, $endpoint, $ghConfig);
            if ($distUrl === null) {
                return false;
            }

            $auth = $ghConfig->basicAuth() ?? '';
            $headers = ($auth !== '') ? ["Authorization: {$auth}"] : [];

            $type = ArchiveDownloader::ZIP;
            $package = new Package($asset->name() . '-assets', 'artifact', 'artifact');
            $package->setDistType($type);
            $package->setDistUrl($distUrl);
            $package->setTargetDir($targetDir);
            $package->setTransportOptions(['http' => ['header' => $headers]]);

            return $this->downloaderFactory->create($type)->download($package, $targetDir);
        } catch (\Throwable $throwable) {
            $this->io->writeError('  ' . $throwable->getMessage());

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
        if ($source === '') {
            return [null, null];
        }

        $repo = $config->repo();
        $userRepo = ($repo !== null) ? explode('/', $repo) : null;
        if (!is_array($userRepo) || (count($userRepo) !== 2) || ($userRepo[0] === '')) {
            return [null, null];
        }

        $endpoint = "https://api.github.com/repos/{$repo}/actions/artifacts";

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return [null, null];
        }

        $safe = filter_var($endpoint, FILTER_SANITIZE_URL);
        ($safe === '') and $safe = null;

        return ($safe === null) ? [$safe, $userRepo[0]] : [null, null];
    }

    /**
     * @param string $name
     * @param non-empty-string $endpoint
     * @param GitHubConfig $config
     * @return non-empty-string|null
     */
    private function retrieveArtifactUrl(
        string $name,
        string $endpoint,
        GitHubConfig $config
    ): ?string {

        $response = $this->client->get($endpoint, [], $config->basicAuth());
        $json = ($response !== '') ? json_decode($response, true) : null;
        if (!is_array($json) || !isset($json['artifacts']) || !is_array($json['artifacts'])) {
            throw new \Exception("Could not obtain a valid API response from {$endpoint}.");
        }

        /** @var non-empty-string|null $artifactUrl */
        $artifactUrl = null;
        foreach ($json['artifacts'] as $item) {
            $artifactUrl = is_array($item) ? $this->artifactUrl($item, $name) : null;
            if ($artifactUrl !== null) {
                break;
            }
        }

        $repo = $config->repo() ?? '';
        if ($artifactUrl === null) {
            $this->io->writeError("  Artifact '{$name}' not found in '{$repo}'.");
        }

        return $artifactUrl;
    }

    /**
     * @param array $data
     * @param string $targetName
     * @return non-empty-string|null
     */
    private function artifactUrl(array $data, string $targetName): ?string
    {
        $name = $data['name'] ?? null;
        if (($name !== $targetName) || !empty($data['expired'])) {
            return null;
        }

        /** @var string $url */
        $url = $data['archive_download_url'] ?? '';
        if (($url !== '') && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }
}
