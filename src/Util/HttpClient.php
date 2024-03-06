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

class HttpClient
{
    /**
     * @param Io $io
     * @param Composer $composer
     * @return HttpClient
     */
    public static function new(HttpDownloader $httpDownloader, Io $io): HttpClient
    {
        return new self($httpDownloader, $io);
    }

    /**
     * @param Io $io
     * @param Composer $composer
     */
    private function __construct(
        private HttpDownloader $httpDownloader,
        private Io $io
    ) {
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
            if (($authorization !== null) && ($authorization !== '')) {
                isset($options['http']) or $options['http'] = [];
                /** @psalm-suppress MixedArrayAssignment */
                isset($options['http']['header']) or $options['http']['header'] = [];
                /** @psalm-suppress MixedArrayAssignment */
                $options['http']['header'][] = "Authorization: {$authorization}";
            }

            $result = null;
            $response = $this->httpDownloader->get($url, $options);
            $statusCode = $response->getStatusCode();
            if (($statusCode > 199) && ($statusCode < 300)) {
                $result = $response->getBody();
            }

            if (($result === '') || !is_string($result)) {
                throw new \Exception("Could not obtain a response from '{$url}'.");
            }

            return $result;
        } catch (\Throwable $throwable) {
            $this->io->writeVerboseError('  ' . $throwable->getMessage());

            return '';
        }
    }
}
