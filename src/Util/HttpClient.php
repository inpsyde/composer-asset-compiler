<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Composer;
use Composer\Util\RemoteFilesystem;

class HttpClient
{

    /**
     * @var Io
     */
    private $io;

    /**
     * @var RemoteFilesystem
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
        $this->client = \Composer\Factory::createRemoteFilesystem(
            $io->composerIo(),
            $composer->getConfig()
        );
    }

    /**
     * @param string $url
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
                isset($options['http']['headers']) or $options['http']['headers'] = [];
                /** @psalm-suppress MixedArrayAssignment */
                $options['http']['headers'][] = "Authorization: {$authorization}";
            }

            $origin = (string)RemoteFilesystem::getOrigin($url);
            $result = $this->client->getContents($origin, $url, false, $options);
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
