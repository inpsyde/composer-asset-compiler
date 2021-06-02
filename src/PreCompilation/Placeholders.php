<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Util\EnvResolver;

class Placeholders
{
    public const ENV = 'env';
    public const HASH = 'hash';
    public const VERSION = 'version';
    public const REFERENCE = 'ref';

    /**
     * @var string
     */
    private $env;

    /**
     * @var string
     */
    private $hash;

    /**
     * @var string|null
     */
    private $version;

    /**
     * @var string|null
     */
    private $reference;

    /**
     * @param Asset $asset
     * @param string $env
     * @param string $hash
     * @return Placeholders
     */
    public static function new(Asset $asset, string $env, string $hash): Placeholders
    {
        return new self($env, $hash, $asset->version(), $asset->reference());
    }

    /**
     * @param string $env
     * @param string $hash
     * @param string|null $version
     * @param string|null $reference
     */
    private function __construct(string $env, string $hash, ?string $version, ?string $reference)
    {
        $this->env = $env;
        $this->hash = $hash;
        $this->version = $version;
        $this->reference = $reference;
    }

    /**
     * @param string $original
     * @param string $hash
     * @param array $environment
     * @return string
     */
    public function replace(string $original, array $environment): string
    {
        $replace = [
            self::HASH => $this->hash,
            self::ENV => $this->env,
            self::VERSION => $this->version,
            self::REFERENCE => $this->reference,
        ];

        $replaced = preg_replace_callback(
            '~\$\{\s*(' . implode('|', array_keys($replace)) . ')\s*\}~i',
            static function (array $matches) use ($replace): string {
                $key = strtolower((string)($matches[1] ?? ''));

                return $replace[$key] ?? '';
            },
            $original
        );

        return $replaced ? EnvResolver::replaceEnvVariables($replaced, $environment) : '';
    }
}
