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

class GithubReleaseZipAdapter implements Adapter
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
     * @return GithubReleaseZipAdapter
     */
    public static function new(
        Io $io,
        HttpClient $client,
        ArchiveDownloaderFactory $archiveDownloaderFactory
    ): GithubReleaseZipAdapter {

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
        return 'gh-release-zip';
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

        $version = $asset->version();

        try {
            if (!$version) {
                $this->io->writeError('  Invalid configuration for GitHub release zip.');

                return false;
            }

            $ghConfig = GitHubConfig::new($config, $environment);

            [$endpoint, $owner] = $this->buildReleaseEndpoint($source, $ghConfig, $version);
            if (!$endpoint || !$owner) {
                $this->io->writeError('  Invalid GitHub release configuration.');

                return false;
            }

            $distUrl = $this->retrieveArchiveUrl($source, $endpoint, $ghConfig, $version);
            if (!$distUrl) {
                return false;
            }

            $headers = ['Accept: application/octet-stream'];
            $auth = $ghConfig->basicAuth();
            $auth and $headers[] = "Authorization: {$auth}";

            $type = ArchiveDownloader::ZIP;
            $package = new Package($asset->name() . '-assets', 'release-zip', 'release-zip');
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
     * @param string $version
     * @return array{string,string}|array{null,null}
     */
    private function buildReleaseEndpoint(string $source, GitHubConfig $config, string $version): array
    {
        if (!$source) {
            return [null, null];
        }

        $repo = $config->repo();
        $userRepo = $repo ? explode('/', $repo) : null;
        if (!$userRepo || count($userRepo) !== 2) {
            return [null, null];
        }

        $endpoint = "https://api.github.com/repos/{$repo}/releases/tags/{$version}";
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return [null, null];
        }

        $safe = filter_var($endpoint, FILTER_SANITIZE_URL);
        $safe && is_string($safe) or $safe = null;

        return $safe ? [$safe, reset($userRepo) ?: ''] : [null, null];
    }

    /**
     * @param string $targetName
     * @param string $endpoint
     * @param GitHubConfig $config
     * @param string $version
     * @return string|null
     */
    private function retrieveArchiveUrl(
        string $targetName,
        string $endpoint,
        GitHubConfig $config,
        string $version
    ): ?string {

        $response = $this->client->get($endpoint, [], $config->basicAuth());
        $json = $response ? json_decode($response, true) : null;
        if (!$json || !is_array($json)) {
            throw new \Exception("Could not obtain a valid API response from {$endpoint}.");
        }

        $assets = $json['assets'] ?? null;
        if (!$assets || !is_array($assets)) {
            $this->io->write("  Release '{$version}' has no binary assets.");

            return null;
        }

        if (strtolower(pathinfo($targetName, PATHINFO_EXTENSION) ?: '') !== 'zip') {
            $targetName .= '.zip';
        }

        $id = $this->findBinaryId($assets, $targetName);
        $repo = $config->repo() ?: '';
        $id or $this->io->writeError("  Release binary '{$targetName}' not found.");

        return $id ? "https://api.github.com/repos/{$repo}/releases/assets/{$id}" : null;
    }

    /**
     * @param array $items
     * @param string $targetName
     * @return string|null
     */
    private function findBinaryId(array $items, string $targetName): ?string
    {
        foreach ($items as $item) {
            if (
                is_array($item)
                && !empty($item['name'])
                && ($item['name'] === $targetName)
                && !empty($item['id'])
                && is_scalar($item['id'])
            ) {
                return (string)$item['id'];
            }
        }

        return null;
    }
}
