<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\Tests\TestCase;

class EnvResolverTest extends TestCase
{
    public function testEnvVarResolution()
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

    public function testEnvReturnedWhenSet()
    {
        $resolver = new EnvResolver('foo', false);

        static::assertSame('foo', $resolver->env());
    }

    public function testEnvDefaultIsReturnedWhenNoEnv()
    {
        $noDev = new EnvResolver(null, false);
        $dev = new EnvResolver(null, true);

        static::assertSame(EnvResolver::ENV_DEFAULT_NO_DEV, $noDev->env());

        static::assertSame(EnvResolver::ENV_DEFAULT, $dev->env());
    }

    public function testResolveCurrentEnv()
    {
        $resolver = new EnvResolver('foo', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT_NO_DEV => 'def-no-dev',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('yes!', $resolver->resolve($data));
    }

    public function testResolveFallbackNoDev()
    {
        $resolver = new EnvResolver('bar', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT_NO_DEV => 'def-no-dev',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def-no-dev', $resolver->resolve($data));
    }

    public function testResolveFallbackDefaultWhenNoDev()
    {
        $resolver = new EnvResolver('bar', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def', $resolver->resolve($data));
    }

    public function testResolveFallbackDefault()
    {
        $resolver = new EnvResolver('bar', true);

        $data = [
            'env' => [
                'foo' => 'yes!',
                EnvResolver::ENV_DEFAULT_NO_DEV => 'def-no-dev',
                EnvResolver::ENV_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def', $resolver->resolve($data));
    }
}
