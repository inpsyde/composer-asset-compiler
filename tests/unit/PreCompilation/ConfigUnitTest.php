<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\PreCompilation;

use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Config as AssetConfig;
use Inpsyde\AssetsCompiler\PreCompilation\Config;
use Inpsyde\AssetsCompiler\PreCompilation\Placeholders;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use Inpsyde\AssetsCompiler\Util\ModeResolver;

class ConfigUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testParsePrecompilationSingle(): void
    {
        $config = Config::new(
            [
                'source' => 'https://example.com/foo-${ref}.zip',
                'target' => '.',
                'adapter' => 'archive',
                'config' => [
                    'auth' => [
                        'user' => '${USR}',
                        'password' => '${SECRET}',
                    ],
                ],
            ],
            ModeResolver::new('test', true)
        );

        $placeholders = $this->factoryPlaceholders('1.0', 'thisIsRef', 'thisIsHash');

        static::assertSame(
            'https://example.com/foo-thisIsRef.zip',
            $config->source($placeholders, [])
        );

        static::assertSame(
            ['auth' => ['user' => 'usr', 'password' => '53cr3t']],
            $config->config($placeholders, ['USR' => 'usr', 'SECRET' => '53cr3t'])
        );

        static::assertSame('.', $config->target($placeholders));
        static::assertSame('archive', $config->adapter($placeholders));
    }

    /**
     * @test
     */
    public function testParsePrecompilationSingleFromEnv(): void
    {
        $config = Config::new(
            [
                'env' => [
                    'local' => [
                        'adapter' => false,
                    ],
                    'test' => [
                        'source' => 'https://example.com/v${version}.zip',
                        'target' => '.',
                        'adapter' => 'archive',
                    ],
                ],
            ],
            ModeResolver::new('test', true)
        );

        $placeholders = $this->factoryPlaceholders('1.0', 'thisIsRef', 'thisIsHash');

        static::assertSame(
            'https://example.com/v1.0.zip',
            $config->source($placeholders, [])
        );

        static::assertSame('.', $config->target($placeholders));
        static::assertSame('archive', $config->adapter($placeholders));
    }

    /**
     * @test
     */
    public function testParsePrecompilationSingleSkippedNoVersion(): void
    {
        $config = Config::new(
            [
                'source' => 'https://example.com/foo-${version}.zip',
                'target' => '.',
                'adapter' => 'archive',
            ],
            ModeResolver::new('test', true)
        );

        $placeholders = $this->factoryPlaceholders('', 'thisIsRef', 'thisIsHash');

        static::assertNull($config->source($placeholders, []));
        static::assertNull($config->target($placeholders));
        static::assertNull($config->adapter($placeholders));
    }

    /**
     * @test
     */
    public function testParsePrecompilationSingleSkippedNoRef(): void
    {
        $config = Config::new(
            [
                'source' => 'https://example.com/foo-${ref}.zip',
                'target' => '.',
                'adapter' => 'archive',
            ],
            ModeResolver::new('test', true)
        );

        $placeholders = $this->factoryPlaceholders('1.0', '', 'thisIsHash');

        static::assertNull($config->source($placeholders, []));
        static::assertNull($config->target($placeholders));
        static::assertNull($config->adapter($placeholders));
    }

    /**
     * @test
     */
    public function testParsePrecompilationSingleSkippedNoHash(): void
    {
        $config = Config::new(
            [
                'source' => 'https://example.com/foo-${hash}.zip',
                'target' => '.',
                'adapter' => 'archive',
            ],
            ModeResolver::new('test', true)
        );

        $placeholders = $this->factoryPlaceholders('1.0', 'thisIsRef', '');

        static::assertNull($config->source($placeholders, []));
        static::assertNull($config->target($placeholders));
        static::assertNull($config->adapter($placeholders));
    }

    /**
     * @test
     */
    public function testMultiple(): void
    {
        $config = Config::new(
            [
                [
                    'source' => 'https://example.com/v${version}.zip',
                    'target' => '.',
                    'adapter' => 'archive',
                ],
                [
                    'source' => 'https://example.com/ref/${ref}.zip',
                    'target' => '.',
                    'adapter' => 'archive',
                ],
            ],
            ModeResolver::new('test', true)
        );

        $placeholders = $this->factoryPlaceholders('1.0', 'thisIsRef', 'thisIsHash');

        static::assertSame('https://example.com/v1.0.zip', $config->source($placeholders, []));
    }

    /**
     * @test
     */
    public function testMultipleSkipNoVersion(): void
    {
        $config = Config::new(
            [
                [
                    'source' => 'https://example.com/v${version}.zip',
                    'target' => '.',
                    'adapter' => 'archive',
                ],
                [
                    'source' => 'https://example.com/ref/${ref}.zip',
                    'target' => '.',
                    'adapter' => 'archive',
                ],
            ],
            ModeResolver::new('test', true)
        );

        $phNoVer = $this->factoryPlaceholders('', 'aaa', 'thisIsHash');
        $phVer = $this->factoryPlaceholders('1.0', 'aaa', 'thisIsHash');

        static::assertSame('https://example.com/ref/aaa.zip', $config->source($phNoVer, []));
        static::assertSame('https://example.com/v1.0.zip', $config->source($phVer, []));
    }

    /**
     * @test
     */
    public function testMultipleChoiceByStability(): void
    {
        $config = Config::new(
            [
                [
                    'source' => 'https://example.com/v${version}.zip',
                    'target' => '.',
                    'adapter' => 'archive',
                    'stability' => 'stable',
                ],
                [
                    'source' => 'https://example.com/ref/${ref}.zip',
                    'target' => '.',
                    'adapter' => 'archive',
                    'stability' => 'dev',
                ],
            ],
            ModeResolver::new('test', true)
        );

        $phDev = $this->factoryPlaceholders('dev-master', 'aaa', 'x');
        $phStable = $this->factoryPlaceholders('1.0.0', 'aaa', 'y');

        static::assertSame('https://example.com/ref/aaa.zip', $config->source($phDev, []));
        static::assertSame('https://example.com/v1.0.0.zip', $config->source($phStable, []));
    }

    /**
     * @test
     */
    public function testMultipleByEnv(): void
    {
        $config = Config::new(
            [
                'env' => [
                    '$default' => [
                        'adapter' => false,
                    ],
                    'test' => [
                        [
                            'source' => 'https://example.com/v${version}.zip',
                            'target' => '.',
                            'adapter' => 'archive',
                            'stability' => 'stable',
                        ],
                        [
                            'source' => 'https://example.com/ref/${ref}.zip',
                            'target' => '.',
                            'adapter' => 'archive',
                        ],
                    ],
                ],
            ],
            ModeResolver::new('test', true)
        );

        $placeholders = $this->factoryPlaceholders('1.0-dev', 'r3f', 'h45h');

        static::assertSame('https://example.com/ref/r3f.zip', $config->source($placeholders, []));
    }

    /**
     * @param string $version
     * @param string $ref
     * @param string $hash
     * @return Placeholders
     */
    private function factoryPlaceholders(string $version, string $ref, string $hash): Placeholders
    {
        $config = AssetConfig::forAssetConfigInRoot(true, ModeResolver::new('test', true));
        $asset = Asset::new('inpsyde/test', $config, __DIR__, $version, $ref);

        return Placeholders::new($asset, 'test', $hash);
    }
}
