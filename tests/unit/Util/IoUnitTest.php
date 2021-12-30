<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Util;

use Composer\IO\IOInterface;
use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;

class IoUnitTest extends UnitTestCase
{
    private const SPACER = '    ';

    /**
     * @test
     */
    public function testWrite(): void
    {
        $composerIo = \Mockery::mock(IOInterface::class);
        $composerIo->expects('write')->with(self::SPACER . 'foo');
        $composerIo->expects('write')->with(self::SPACER . 'bar');

        Io::new($composerIo)->write('foo', 'bar');
    }

    /**
     * @test
     */
    public function testWriteVerbose(): void
    {
        $composerIo = \Mockery::mock(IOInterface::class);

        $composerIo->expects('write')
            ->with(self::SPACER . 'foo', true, IOInterface::VERBOSE);

        $composerIo->expects('write')
            ->with(self::SPACER . 'bar', true, IOInterface::VERBOSE);

        Io::new($composerIo)->writeVerbose('foo', 'bar');
    }

    /**
     * @test
     */
    public function testWriteInfo(): void
    {
        $composerIo = \Mockery::mock(IOInterface::class);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<info>a</info>', true, IOInterface::NORMAL);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<info>b</info>', true, IOInterface::NORMAL);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<info>c</info>', true, IOInterface::VERBOSE);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<info>d</info>', true, IOInterface::VERBOSE);

        $io = Io::new($composerIo);

        $io->writeInfo('a', 'b');
        $io->writeVerboseInfo('c', 'd');
    }

    /**
     * @test
     */
    public function testWriteComment(): void
    {
        $composerIo = \Mockery::mock(IOInterface::class);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<comment>a</comment>', true, IOInterface::NORMAL);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<comment>b</comment>', true, IOInterface::NORMAL);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<comment>c</comment>', true, IOInterface::VERBOSE);

        $composerIo
            ->expects('write')
            ->with(self::SPACER . '<comment>d</comment>', true, IOInterface::VERBOSE);

        $io = Io::new($composerIo);

        $io->writeComment('a', 'b');
        $io->writeVerboseComment('c', 'd');
    }

    /**
     * @test
     */
    public function testWriteErrorComment(): void
    {
        $composerIo = \Mockery::mock(IOInterface::class);

        $composerIo
            ->expects('writeError')
            ->with(self::SPACER . '<fg=red>a</>', true, IOInterface::NORMAL);

        $composerIo
            ->expects('writeError')
            ->with(self::SPACER . '<fg=red>b</>', true, IOInterface::NORMAL);

        $composerIo
            ->expects('writeError')
            ->with(self::SPACER . '<fg=red>c</>', true, IOInterface::VERBOSE);

        $composerIo
            ->expects('writeError')
            ->with(self::SPACER . '<fg=red>d</>', true, IOInterface::VERBOSE);

        $io = Io::new($composerIo);

        $io->writeError('a', 'b');
        $io->writeVerboseError('c', 'd');
    }
}
