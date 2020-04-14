<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Composer\IO\IOInterface;
use Inpsyde\AssetsCompiler\Io;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Mockery;

class IoTest extends TestCase
{
    private const SPACER = '    ';

    public function testWrite()
    {
        $composerIo = Mockery::mock(IOInterface::class);
        $composerIo->shouldReceive('write')->once()->with(self::SPACER . 'foo');
        $composerIo->shouldReceive('write')->once()->with(self::SPACER . 'bar');

        $io = new Io($composerIo);
        $io->write('foo', 'bar');
    }

    public function testWriteVerbose()
    {
        $composerIo = Mockery::mock(IOInterface::class);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . 'foo', true, IOInterface::VERBOSE);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . 'bar', true, IOInterface::VERBOSE);

        $io = new Io($composerIo);
        $io->writeVerbose('foo', 'bar');
    }

    public function testWriteInfo()
    {
        $composerIo = Mockery::mock(IOInterface::class);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . '<info>a</info>', true, IOInterface::NORMAL);

        $composerIo
            ->shouldReceive('write')
            ->once()->with(self::SPACER . '<info>b</info>', true, IOInterface::NORMAL);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . '<info>c</info>', true, IOInterface::VERBOSE);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . '<info>d</info>', true, IOInterface::VERBOSE);

        $io = new Io($composerIo);

        $io->writeInfo('a', 'b');
        $io->writeVerboseInfo('c', 'd');
    }

    public function testWriteComment()
    {
        $composerIo = Mockery::mock(IOInterface::class);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . '<comment>a</comment>', true, IOInterface::NORMAL);

        $composerIo
            ->shouldReceive('write')
            ->once()->with(self::SPACER . '<comment>b</comment>', true, IOInterface::NORMAL);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . '<comment>c</comment>', true, IOInterface::VERBOSE);

        $composerIo->shouldReceive('write')
            ->once()
            ->with(self::SPACER . '<comment>d</comment>', true, IOInterface::VERBOSE);

        $io = new Io($composerIo);

        $io->writeComment('a', 'b');
        $io->writeVerboseComment('c', 'd');
    }

    public function testWriteErrorComment()
    {
        $composerIo = Mockery::mock(IOInterface::class);

        $composerIo->shouldReceive('writeError')
            ->once()
            ->with(self::SPACER . '<fg=red>a</>', true, IOInterface::NORMAL);

        $composerIo
            ->shouldReceive('writeError')
            ->once()->with(self::SPACER . '<fg=red>b</>', true, IOInterface::NORMAL);

        $composerIo->shouldReceive('writeError')
            ->once()
            ->with(self::SPACER . '<fg=red>c</>', true, IOInterface::VERBOSE);

        $composerIo->shouldReceive('writeError')
            ->once()
            ->with(self::SPACER . '<fg=red>d</>', true, IOInterface::VERBOSE);

        $io = new Io($composerIo);

        $io->writeError('a', 'b');
        $io->writeVerboseError('c', 'd');
    }
}
