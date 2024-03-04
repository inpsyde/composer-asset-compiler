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
use Inpsyde\AssetsCompiler\Util\Io;

class ArchiveDownloaderAdapter implements Adapter
{
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
        private Io $io,
        private ArchiveDownloaderFactory $downloaderFactory
    ) {
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return 'archive';
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

        $type = $this->determineType($config, $source);

        if (!ArchiveDownloaderFactory::isValidArchiveType($type ?? '')) {
            $typeName = is_string($type) ? $type : gettype($type);
            $message = ($type === null)
                ? "Could not determine archive type for {$source}."
                : "'{$typeName}' is not a valid archive type.";
            $this->io->writeError("  {$message}");

            return false;
        }

        /** @var string $type */

        $safeSource = $this->sanitizeSource($source) ?? '';
        [$distUrl, $auth] = ($safeSource !== '')
            ? $this->extractAuth($safeSource, $config)
            : [null, null];
        if (($distUrl === null) || ($distUrl === '')) {
            return false;
        }

        try {
            $package = new Package($asset->name(), 'stable', 'stable');
            $package->setDistType($type);
            $package->setDistUrl($distUrl);
            $package->setTargetDir($targetDir);
            if (($auth !== null) && ($auth !== '')) {
                $package->setTransportOptions(['http' => ['header' => ["Authorization: {$auth}"]]]);
            }

            return $this->downloaderFactory->create($type)->download($package, $targetDir);
        } catch (\Throwable $throwable) {
            $this->io->writeError('  ' . $throwable->getMessage());

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

        return match (strtolower(pathinfo($source, PATHINFO_EXTENSION) ?: '')) {
            'rar' => ArchiveDownloader::RAR,
            'tar' => ArchiveDownloader::TAR,
            'xz' => ArchiveDownloader::XZ,
            default => ArchiveDownloader::ZIP,
        };
    }

    /**
     * @param string $source
     * @return string|null
     */
    private function sanitizeSource(string $source): ?string
    {
        $safeSource = filter_var($source, FILTER_VALIDATE_URL)
            ? filter_var($source, FILTER_SANITIZE_URL)
            : false;

        if (($safeSource === '') || !is_string($safeSource)) {
            $this->io->writeError("  '{$source}' is not a valid URL.");

            return null;
        }

        return $source;
    }

    /**
     * @param string $source
     * @param array $config
     * @return array{string|null, string|null}
     */
    private function extractAuth(string $source, array $config): array
    {
        preg_match('~^(https?://)(?:([^:]+)(?::([^@]+))?@)?(.+)~i', $source, $matches);
        $schema = $matches[1] ?? '';
        $url = $matches[4] ?? '';

        if (($schema === '') || ($url === '')) {
            $this->io->writeError("  '{$source}' is not a valid URL.");

            return [null, null];
        }

        $auth = $config['auth'] ?? null;
        is_array($auth) and $auth = $this->extractBasicAuth($auth);
        if (is_string($auth) && preg_match('~^\S+\s.+$~', $auth)) {
            return [$source, $auth];
        }

        $user = $matches[2] ?? null;
        $pass = $matches[3] ?? null;
        $auth = $this->extractBasicAuth(compact('user', 'pass'));

        return [$schema . $url, $auth ?? ''];
    }

    /**
     * @param array $config
     * @return non-empty-string|null
     */
    private function extractBasicAuth(array $config): ?string
    {
        $user = $config['user'] ?? $config['usr'] ?? null;
        $pass = $config['password']
            ?? $config['pass']
            ?? $config['secret']
            ?? $config['pwd']
            ?? $config['token']
            ?? '';

        if (($user === '') || !is_string($user) || !is_string($pass)) {
            return null;
        }

        return ($pass === '')
            ? 'Basic ' . base64_encode($user)
            : 'Basic ' . base64_encode("{$user}:{$pass}");
    }
}
