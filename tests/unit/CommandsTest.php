<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Commands;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Mockery;

class CommandsTest extends TestCase
{

    public function testFromDefaultFailsForUnknown()
    {
        $commands = Commands::fromDefault('foo');

        static::assertFalse($commands->isValid());
        static::assertNull($commands->installCmd());
        static::assertNull($commands->updateCmd());
        static::assertNull($commands->scriptCmd('x'));
    }

    public function testFromDefaultWorksForKnown()
    {
        $yarn = Commands::fromDefault('Yarn');
        $npm = Commands::fromDefault('NPM');

        static::assertTrue($yarn->isValid());
        static::assertSame('yarn', $yarn->installCmd());
        static::assertSame('yarn upgrade', $yarn->updateCmd());
        static::assertSame('yarn x', $yarn->scriptCmd('x'));

        static::assertTrue($npm->isValid());
        static::assertSame('npm install', $npm->installCmd());
        static::assertSame('npm update --no-save', $npm->updateCmd());
        static::assertSame('npm run x', $npm->scriptCmd('x'));
    }

    public function testDiscoverYarn()
    {
        $executor = Mockery::mock(ProcessExecutor::class);

        $executor->shouldReceive('execute')
            ->once()
            ->with('yarn --version', \Mockery::any(), __DIR__)
            ->andReturn(0);

        $yarn = Commands::discover($executor, __DIR__);

        static::assertTrue($yarn->isValid());
        static::assertSame('yarn', $yarn->installCmd());
    }

    public function testDiscoverNpm()
    {
        $executor = Mockery::mock(ProcessExecutor::class);

        $executor->shouldReceive('execute')
            ->once()
            ->with('yarn --version', \Mockery::any(), __DIR__)
            ->andReturn(1);

        $executor->shouldReceive('execute')
            ->once()
            ->with('npm --version', \Mockery::any(), __DIR__)
            ->andReturn(0);

        $yarn = Commands::discover($executor, __DIR__);

        static::assertTrue($yarn->isValid());
        static::assertSame('npm install', $yarn->installCmd());
    }

    public function testDiscoverNothing()
    {
        $executor = Mockery::mock(ProcessExecutor::class);

        $executor->shouldReceive('execute')
            ->once()
            ->with('yarn --version', \Mockery::any(), __DIR__)
            ->andReturn(1);

        $executor->shouldReceive('execute')
            ->once()
            ->with('npm --version', \Mockery::any(), __DIR__)
            ->andReturn(1);

        $yarn = Commands::discover($executor, __DIR__);

        static::assertFalse($yarn->isValid());
        static::assertNull($yarn->installCmd());
    }
}
