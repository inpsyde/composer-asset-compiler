<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests;

use Composer\IO\ConsoleIO;
use Inpsyde\AssetsCompiler\Util\Io;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @param int $verbosity
     * @param bool $interactive
     * @return Io
     */
    protected function factoryIo(
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
        bool $interactive = true
    ): Io {

        $input = \Mockery::mock(InputInterface::class);
        $input->shouldReceive('isInteractive')->andReturn($interactive);

        $output = \Mockery::mock(OutputInterface::class);
        $output->shouldReceive('getVerbosity')->andReturn($verbosity);
        $output->shouldReceive('write')->zeroOrMoreTimes();
        $output->shouldReceive('isQuiet')
            ->andReturn($verbosity === OutputInterface::VERBOSITY_QUIET);
        $output->shouldReceive('isDebug')
            ->andReturn($verbosity === OutputInterface::VERBOSITY_DEBUG);
        $output->shouldReceive('isVeryVerbose')
            ->andReturn($verbosity === OutputInterface::VERBOSITY_VERY_VERBOSE);
        $output->shouldReceive('isVerbose')
            ->andReturn($verbosity === OutputInterface::VERBOSITY_VERBOSE);

        $composerIo = new ConsoleIO($input, $output, new HelperSet());

        return Io::new($composerIo);
    }
}
