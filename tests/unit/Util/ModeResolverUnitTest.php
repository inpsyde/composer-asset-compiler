<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Util;

use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;

class ModeResolverUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testEnvReturnedWhenSet(): void
    {
        $resolver = ModeResolver::new('foo', false);

        static::assertSame('foo', $resolver->mode());
    }

    /**
     * @test
     */
    public function testEnvDefaultIsReturnedWhenNoEnv(): void
    {
        $noDev = ModeResolver::new(null, false);
        $dev = ModeResolver::new(null, true);

        static::assertSame(ModeResolver::MODE_DEFAULT_NO_DEV, $noDev->mode());

        static::assertSame(ModeResolver::MODE_DEFAULT, $dev->mode());
    }

    /**
     * @test
     */
    public function testResolveCurrentEnv(): void
    {
        $resolver = ModeResolver::new('foo', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                ModeResolver::MODE_DEFAULT_NO_DEV => 'def-no-dev',
                ModeResolver::MODE_DEFAULT => 'def',
            ],
        ];

        static::assertSame('yes!', $resolver->resolveConfig($data));
    }

    /**
     * @test
     */
    public function testResolveFallbackNoDev(): void
    {
        $resolver = ModeResolver::new('bar', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                ModeResolver::MODE_DEFAULT_NO_DEV => 'def-no-dev',
                ModeResolver::MODE_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def-no-dev', $resolver->resolveConfig($data));
    }

    /**
     * @test
     */
    public function testResolveFallbackDefaultWhenNoDev(): void
    {
        $resolver = ModeResolver::new('bar', false);

        $data = [
            'env' => [
                'foo' => 'yes!',
                ModeResolver::MODE_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def', $resolver->resolveConfig($data));
    }

    /**
     * @test
     */
    public function testResolveFallbackDefault(): void
    {
        $resolver = ModeResolver::new('bar', true);

        $data = [
            'env' => [
                'foo' => 'yes!',
                ModeResolver::MODE_DEFAULT_NO_DEV => 'def-no-dev',
                ModeResolver::MODE_DEFAULT => 'def',
            ],
        ];

        static::assertSame('def', $resolver->resolveConfig($data));
    }
}
