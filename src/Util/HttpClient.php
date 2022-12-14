<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Composer;
use Composer\Util\HttpDownloader;
use Composer\Util\RemoteFilesystem;

class HttpClient
{
    /**
     * @var Io
     */
    private $io;

    /**
     * @var HttpDownloader|RemoteFilesystem|mixed
     */
    private $client;

    /**
     * @param Io $io
     * @param Composer $composer
     * @return HttpClient
     */
    public static function new(Io $io, Composer $composer): HttpClient
    {
        return new self($io, $composer);
    }

    /**
     * @param Io $io
     * @param Composer $composer
     */
    private function __construct(Io $io, Composer $composer)
    {
        $this->io = $io;
        if (is_callable([\Composer\Factory::class, 'createHttpDownloader'])) {
            $this->client = \Composer\Factory::createHttpDownloader(
                $io->composerIo(),
                $composer->getConfig()
            );

            return;
        }

        /** @noinspection PhpUndefinedMethodInspection */
        $this->client = \Composer\Factory::createRemoteFilesystem(
            $io->composerIo(),
            $composer->getConfig()
        );
    }

    /**
     * @param non-empty-string $url
     * @param array $options
     * @param string|null $authorization
     * @return string
     */
    public function get(string $url, array $options = [], ?string $authorization = null): string
    {
        try {
            if ($authorization) {
                isset($options['http']) or $options['http'] = [];
                /** @psalm-suppress MixedArrayAssignment */
                isset($options['http']['header']) or $options['http']['header'] = [];
                /** @psalm-suppress MixedArrayAssignment */
                $options['http']['header'][] = "Authorization: {$authorization}";
            }

            $result = null;
            if ($this->client instanceof HttpDownloader) {
                $response = $this->client->get($url, $options);
                $statusCode = $response->getStatusCode();
                if ($statusCode > 199 && $statusCode < 300) {
                    $result = $response->getBody();
                }
            } elseif ($this->client instanceof RemoteFilesystem) {
                /** @psalm-suppress UndefinedMethod */
                $origin = (string)RemoteFilesystem::getOrigin($url);
                /** @psalm-suppress InternalMethod */
                $result = $this->client->getContents($origin, $url, false, $options);
            }

            if (!$result || !is_string($result)) {
                throw new \Exception("Could not obtain a response from '{$url}'.");
            }

            return $result;
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return '';
        }
    }
}
