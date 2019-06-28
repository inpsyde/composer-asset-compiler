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
