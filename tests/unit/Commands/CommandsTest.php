<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Commands;

use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Commands\Commands;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

class CommandsTest extends TestCase
{
    public function testFromDefaultFailsForUnknown()
    {
        $commands = Commands::fromDefault('foo');
        $io = $this->factoryIo();

        static::assertFalse($commands->isValid());
        static::assertNull($commands->installCmd($io));
        static::assertNull($commands->updateCmd($io));
        static::assertNull($commands->scriptCmd('x'));
    }

    public function testFromDefaultWorksForKnown()
    {
        $yarn = Commands::fromDefault('Yarn');
        $npm = Commands::fromDefault('NPM');
        $io = $this->factoryIo();

        static::assertTrue($yarn->isValid());
        static::assertSame('yarn', $yarn->installCmd($io));
        static::assertSame('yarn upgrade', $yarn->updateCmd($io));
        static::assertSame('yarn x', $yarn->scriptCmd('x'));

        static::assertTrue($npm->isValid());
        static::assertSame('npm install', $npm->installCmd($io));
        static::assertSame('npm update --no-save', $npm->updateCmd($io));
        static::assertSame('npm run x', $npm->scriptCmd('x'));
    }

    public function testDiscoverYarn()
    {
        $executor = \Mockery::mock(ProcessExecutor::class);

        $executor->shouldReceive('execute')
            ->once()
            ->with('yarn --version', \Mockery::any(), __DIR__)
            ->andReturn(0);

        $io = $this->factoryIo();
        $yarn = Commands::discover($executor, __DIR__);

        static::assertTrue($yarn->isValid());
        static::assertSame('yarn', $yarn->installCmd($io));
    }

    public function testDiscoverNpm()
    {
        $executor = \Mockery::mock(ProcessExecutor::class);

        $executor->shouldReceive('execute')
            ->once()
            ->with('yarn --version', \Mockery::any(), __DIR__)
            ->andReturn(1);

        $executor->shouldReceive('execute')
            ->once()
            ->with('npm --version', \Mockery::any(), __DIR__)
            ->andReturn(0);

        $yarn = Commands::discover($executor, __DIR__);
        $io = $this->factoryIo();

        static::assertTrue($yarn->isValid());
        static::assertSame('npm install', $yarn->installCmd($io));
    }

    public function testDiscoverNothing()
    {
        $executor = \Mockery::mock(ProcessExecutor::class);

        $executor->shouldReceive('execute')
            ->once()
            ->with('yarn --version', \Mockery::any(), __DIR__)
            ->andReturn(1);

        $executor->shouldReceive('execute')
            ->once()
            ->with('npm --version', \Mockery::any(), __DIR__)
            ->andReturn(1);

        $yarn = Commands::discover($executor, __DIR__);
        $io = $this->factoryIo();

        static::assertFalse($yarn->isValid());
        static::assertNull($yarn->installCmd($io));
    }

    /**
     * @return void
     */
    public function testYarnVerbosity()
    {
        $commands = Commands::fromDefault('Yarn');

        $veryVeryVerbose = $this->factoryIo(OutputInterface::VERBOSITY_DEBUG);
        $veryVerbose = $this->factoryIo(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $verbose = $this->factoryIo(OutputInterface::VERBOSITY_VERBOSE);
        $normal = $this->factoryIo(OutputInterface::VERBOSITY_NORMAL);
        $quiet = $this->factoryIo(OutputInterface::VERBOSITY_QUIET);
        $quietNoInt = $this->factoryIo(OutputInterface::VERBOSITY_QUIET, false);

        static::assertSame('yarn --verbose', $commands->installCmd($veryVeryVerbose));
        static::assertSame('yarn', $commands->installCmd($veryVerbose));
        static::assertSame('yarn', $commands->installCmd($verbose));
        static::assertSame('yarn', $commands->installCmd($normal));
        static::assertSame('yarn --silent', $commands->installCmd($quiet));
        static::assertSame('yarn --non-interactive --silent', $commands->installCmd($quietNoInt));
    }

    /**
     * @return void
     */
    public function testNpmVerbosity()
    {
        $commands = Commands::fromDefault('npm');

        $veryVeryVerbose = $this->factoryIo(OutputInterface::VERBOSITY_DEBUG);
        $veryVerbose = $this->factoryIo(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $verbose = $this->factoryIo(OutputInterface::VERBOSITY_VERBOSE);
        $normal = $this->factoryIo(OutputInterface::VERBOSITY_NORMAL);
        $quiet = $this->factoryIo(OutputInterface::VERBOSITY_QUIET);
        $quietNoInt = $this->factoryIo(OutputInterface::VERBOSITY_QUIET, false);

        static::assertSame('npm install -ddd', $commands->installCmd($veryVeryVerbose));
        static::assertSame('npm install -dd', $commands->installCmd($veryVerbose));
        static::assertSame('npm install -d', $commands->installCmd($verbose));
        static::assertSame('npm install', $commands->installCmd($normal));
        static::assertSame('npm install --silent', $commands->installCmd($quiet));
        static::assertSame('npm install --silent', $commands->installCmd($quietNoInt));
    }

    /**
     * @return void
     */
    public function testNpmVerbosityWhenVerbosityInCommandDefined()
    {
        $commands = Commands::new(
            [
                'dependencies' => [
                    'install' => 'npm install --loglevel warn',
                    'update' => 'npm update -d',
                ],
            ]
        );

        $veryVeryVerbose = $this->factoryIo(OutputInterface::VERBOSITY_DEBUG);
        $veryVerbose = $this->factoryIo(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $verbose = $this->factoryIo(OutputInterface::VERBOSITY_VERBOSE);
        $normal = $this->factoryIo(OutputInterface::VERBOSITY_NORMAL);
        $quiet = $this->factoryIo(OutputInterface::VERBOSITY_QUIET);
        $quietNoInt = $this->factoryIo(OutputInterface::VERBOSITY_QUIET, false);

        static::assertSame('npm install --loglevel warn', $commands->installCmd($veryVeryVerbose));
        static::assertSame('npm install --loglevel warn', $commands->installCmd($veryVerbose));
        static::assertSame('npm install --loglevel warn', $commands->installCmd($verbose));
        static::assertSame('npm install --loglevel warn', $commands->installCmd($normal));
        static::assertSame('npm install --loglevel warn', $commands->installCmd($quiet));
        static::assertSame('npm install --loglevel warn', $commands->installCmd($quietNoInt));

        static::assertSame('npm update -d', $commands->updateCmd($veryVeryVerbose));
        static::assertSame('npm update -d', $commands->updateCmd($veryVerbose));
        static::assertSame('npm update -d', $commands->updateCmd($verbose));
        static::assertSame('npm update -d', $commands->updateCmd($normal));
        static::assertSame('npm update -d', $commands->updateCmd($quiet));
        static::assertSame('npm update -d', $commands->updateCmd($quietNoInt));
    }

    public function testAdditionalArguments()
    {
        $yarn = Commands::fromDefault('yarn');
        $npm = Commands::fromDefault('npm');

        $script = 'build -- --name=value';

        static::assertSame('yarn build --name=value', $yarn->scriptCmd($script));
        static::assertSame('npm run build -- --name=value', $npm->scriptCmd($script));
    }
}
