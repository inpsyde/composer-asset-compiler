<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Util;

use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Tests\TestCase;

class EnvResolverTest extends TestCase
{
    /**
     * @test
     */
    public function testEnvVarResolution(): void
    {
        $backup = [$_SERVER, $_ENV];

        $_SERVER['foo'] = 'FOO';
        $_SERVER['bar'] = 'BAR';
        $_SERVER['HTTP_ACCEPT'] = 'text/plain';
        $_ENV['x'] = 'X!';
        $_ENV['y'] = 'Y!';
        putenv('foo=I WIN');
        putenv('meh=MEH');

        static::assertSame('I WIN', EnvResolver::readEnv('foo'));
        static::assertSame('MEH', EnvResolver::readEnv('meh'));
        static::assertSame('X!', EnvResolver::readEnv('x'));
        static::assertSame('Y!', EnvResolver::readEnv('y'));
        static::assertSame('BAR', EnvResolver::readEnv('bar'));
        static::assertSame(null, EnvResolver::readEnv('HTTP_ACCEPT'));
        static::assertSame(null, EnvResolver::readEnv('xyz'));

        $_SERVER = $backup[0];
        $_ENV = $backup[1];
        putenv('foo');
        putenv('meh');
    }

    /**
     * @test
     */
    public function testEnvReturnedWhenSet(): void
    {
        $resolver = EnvResolver::new('foo', false);

        static::assertSame('foo', $resolver->env());
    }

    /**
     * @test
     */
    public function testEnvDefaultIsReturnedWhenNoEnv(): void
    {
        $noDev = EnvResolver::new(null, false);
        $dev = EnvResolver::new(null, true);

        static::assertSame(EnvResolver::ENV_DEFAULT_NO_DEV, $noDev->env());

        static::assertSame(EnvResolver::ENV_DEFAULT, $dev->env());
    }

    /**
     * @test
     */
    public function testResolveCurrentEnv(): void
    {
        $resolver = EnvResolver::new('foo', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT_NO_DEV => 'def-no-dev',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('yes!', $resolver->resolveConfig($data));
    }

    /**
     * @test
     */
    public function testResolveFallbackNoDev(): void
    {
        $resolver = EnvResolver::new('bar', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT_NO_DEV => 'def-no-dev',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def-no-dev', $resolver->resolveConfig($data));
    }

    /**
     * @test
     */
    public function testResolveFallbackDefaultWhenNoDev(): void
    {
        $resolver = EnvResolver::new('bar', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def', $resolver->resolveConfig($data));
    }

    /**
     * @test
     */
    public function testResolveFallbackDefault(): void
    {
        $resolver = EnvResolver::new('bar', true);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT_NO_DEV => 'def-no-dev',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def', $resolver->resolveConfig($data));
    }
}
