<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Util;

use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use Inpsyde\AssetsCompiler\Util\Env;

/**
 * @runTestsInSeparateProcesses
 */
class EnvUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testEnvVarResolution(): void
    {
        $backup = [$_SERVER, $_ENV];

        $_SERVER['foo'] = 'I WIN';
        $_SERVER['bar'] = 'BAR';
        $_SERVER['HTTP_ACCEPT'] = 'text/plain';
        $_ENV['x'] = 'X!';
        $_ENV['y'] = 'Y!';
        putenv('foo=FOO');
        putenv('meh=MEH');

        static::assertSame('I WIN', Env::readEnv('foo'));
        static::assertSame('MEH', Env::readEnv('meh'));
        static::assertSame('X!', Env::readEnv('x'));
        static::assertSame('Y!', Env::readEnv('y'));
        static::assertSame('BAR', Env::readEnv('bar'));
        static::assertSame('text/plain', Env::readEnv('HTTP_ACCEPT'));
        static::assertSame(null, Env::readEnv('xyz'));

        $_SERVER = $backup[0];
        $_ENV = $backup[1];
        putenv('foo');
        putenv('meh');
    }
}
