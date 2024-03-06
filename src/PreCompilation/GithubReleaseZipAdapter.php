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
     * @param Io $io
     * @param HttpClient $client
     * @param ArchiveDownloaderFactory $downloaderFactory
     * @return GithubReleaseZipAdapter
     */
    public static function new(
        Io $io,
        HttpClient $client,
        ArchiveDownloaderFactory $downloaderFactory
    ): GithubReleaseZipAdapter {

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

        $version = $asset->version() ?? '';

        try {
            if ($version === '') {
                $this->io->writeError('  Invalid configuration for GitHub release zip.');

                return false;
            }

            $ghConfig = GitHubConfig::new($config, $environment);

            [$endpoint, $owner] = $this->buildReleaseEndpoint($source, $ghConfig, $version);
            $endpoint ??= '';
            $owner ??= '';
            if (($endpoint === '') || ($owner === '')) {
                $this->io->writeError('  Invalid GitHub release configuration.');

                return false;
            }

            $distUrl = $this->retrieveArchiveUrl($source, $endpoint, $ghConfig, $version) ?? '';
            if ($distUrl === '') {
                return false;
            }

            $headers = ['Accept: application/octet-stream'];
            $auth = $ghConfig->basicAuth() ?? '';
            ($auth !== '') and $headers[] = "Authorization: {$auth}";

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
    private function buildReleaseEndpoint(
        string $source,
        GitHubConfig $config,
        string $version
    ): array {

        if (!$source) {
            return [null, null];
        }

        $repo = $config->repo();
        $userRepo = ($repo !== null) ? explode('/', $repo) : null;
        if (!is_array($userRepo) || (count($userRepo) !== 2) || ($userRepo[0] === '')) {
            return [null, null];
        }

        $ref = $config->ref() ?? $version;
        $endpoint = "https://api.github.com/repos/{$repo}/releases/tags/{$ref}";
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return [null, null];
        }

        $safe = filter_var($endpoint, FILTER_SANITIZE_URL);
        ($safe === '') and $safe = null;

        return ($safe !== null) ? [$safe, $userRepo[0]] : [null, null];
    }

    /**
     * @param string $targetName
     * @param non-empty-string $endpoint
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
        if (($json === null) || ($json === []) || !is_array($json)) {
            throw new \Exception("Could not obtain a valid API response from {$endpoint}.");
        }

        $assets = $json['assets'] ?? [];
        if (($assets === []) || !is_array($assets)) {
            $this->io->write("  Release '{$version}' has no binary assets.");

            return null;
        }

        if (strtolower(pathinfo($targetName, PATHINFO_EXTENSION) ?: '') !== 'zip') {
            $targetName .= '.zip';
        }

        $id = $this->findBinaryId($assets, $targetName) ?? '';
        $repo = $config->repo() ?? '';
        if ($id === '') {
            $this->io->writeError("  Release binary '{$targetName}' not found.");

            return null;
        }

        return "https://api.github.com/repos/{$repo}/releases/assets/{$id}";
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
                && array_key_exists('name', $item)
                && ($item['name'] === $targetName)
                && array_key_exists('id', $item)
                && is_scalar($item['id'])
            ) {
                return (string) $item['id'];
            }
        }

        return null;
    }
}
