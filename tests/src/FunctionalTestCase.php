<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests;

use Composer\Composer;
use Composer\Factory as ComposerFactory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Process\Factory as ProcessFactory;
use Inpsyde\AssetsCompiler\Util\Factory;

abstract class FunctionalTestCase extends \PHPUnit\Framework\TestCase
{
    /** @var string|null */
    protected $cwd = null;

    /** @var string|null */
    protected $baseDir = null;

    /** @var Factory|null */
    protected $factory = null;

    /** @var TestIo|null */
    protected $io = null;

    /** @var bool */
    private $composerInstalled = false;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->cwd = getcwd();
        parent::setUp();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->baseDir && $this->composerInstalled) {
            $filesystem = new Filesystem();
            $filesystem->removeDirectory($this->baseDir . '/vendor');
            $filesystem->remove($this->baseDir . '/.htaccess');
            $filesystem->remove($this->baseDir . '/composer.lock');
        }
        $this->cwd and chdir($this->cwd);
        $this->cwd = null;
        $this->baseDir = null;
        if ($this->io && $this->io->errors) {
            throw new \Error(implode("\n", $this->io->errors));
        }

        $this->io = null;

        parent::tearDown();
    }

    /**
     * @param int $verbosity
     * @return IOInterface
     */
    protected function factoryComposerIo(int $verbosity = IOInterface::NORMAL): IOInterface
    {
        $this->io = new TestIo($verbosity);

        return $this->io;
    }

    /**
     * @param IOInterface $io
     * @return Composer
     */
    protected function factoryComposer(IOInterface $io): Composer
    {
        if (!$this->baseDir) {
            throw new \Error('Please set base dir.');
        }
        putenv('COMPOSER_HOME=' . $this->baseDir);
        putenv('COMPOSER_CACHE_DIR=' . $this->baseDir);
        putenv('COMPOSER_AUTH');
        $factory = new ComposerFactory();

        return $factory->createComposer($io, null, false, $this->baseDir);
    }

    /**
     * @param int $verbosity
     * @param string $mode
     * @param bool $isDev
     * @param string $ignoreLock
     * @return Factory
     */
    protected function factoryFactory(
        int $verbosity = IOInterface::NORMAL,
        string $mode = 'tests',
        bool $isDev = true,
        string $ignoreLock = ''
    ): Factory {

        $io = $this->factoryComposerIo($verbosity);
        $composer = $this->factoryComposer($io);

        return Factory::new($composer, $io, $mode, $isDev, $ignoreLock);
    }

    /**
     * @param string $dir
     * @return void
     */
    protected function moveDir(string $dir)
    {
        $this->baseDir = $dir;
        chdir($dir);
        putenv('COMPOSER=' . "{$dir}/composer.json");
    }

    /**
     * @param string $dir
     * @return void
     */
    protected function composerUpdate(string $dir): void
    {
        if ($dir !== $this->baseDir) {
            $this->moveDir($dir);
        }
        $process = ProcessFactory::new()->create("composer update -n -q --no-cache", $dir);
        $process->mustRun();
        $this->composerInstalled = true;
    }

    /**
     * @param string $dir
     * @return void
     */
    protected function composerCommand(string $command): void
    {
        if (!$this->composerInstalled) {
            if (!$this->baseDir) {
                throw new \Error('Please install Composer deps or set base dir.');
            }
            $this->composerUpdate($this->baseDir);
        }

        $process = ProcessFactory::new()->create($command, $this->baseDir);
        $process->mustRun();
    }
}
