<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Asset;

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Asset\Processor;
use Inpsyde\AssetsCompiler\PackageManager\Finder;
use Inpsyde\AssetsCompiler\PreCompilation\Handler;
use Inpsyde\AssetsCompiler\Process\ParallelManager;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use Inpsyde\AssetsCompiler\Util\Io;
use Mockery;

class ProcessorUnitTest extends UnitTestCase
{
    public function testCreation(): void
    {
        $io = Mockery::mock(Io::class);
        $config = Mockery::mock(Config::class);
        $packageManagerFinder = Mockery::mock(Finder::class);
        $processExecutor = Mockery::mock(ProcessExecutor::class);
        $parallelManager = Mockery::mock(ParallelManager::class);
        $locker = Mockery::mock(Locker::class);
        $preCompiler = Mockery::mock(Handler::class);
        $outputHanlder = static fn () => null;
        $filesystem = Mockery::mock(Filesystem::class);

        $processor = Processor::new(
            $io,
            $config,
            $packageManagerFinder,
            $processExecutor,
            $parallelManager,
            $locker,
            $preCompiler,
            $outputHanlder,
            $filesystem,
        );

        $this->assertInstanceOf(Processor::class, $processor);
    }
}
