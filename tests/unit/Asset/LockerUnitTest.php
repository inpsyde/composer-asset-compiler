<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Asset;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\HashBuilder;
use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\PathsFinder;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class LockerUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testIsLockedIsFalseIfNoFileExists(): void
    {
        $locker = $this->factoryLocker();

        static::assertFalse($locker->isLocked($this->factorPackage(['script' => 'test'])));
    }

    /**
     * @test
     */
    public function testIsLockedIsFalseForEmptyFileAndErrorWritten(): void
    {
        $io = \Mockery::mock(Io::class);
        $io->allows('isVerbose')->andReturn(true);
        $io
            ->expects('writeVerboseError')
            ->andReturnUsing(
                static function (string $arg): void {
                    static::assertStringContainsString('lock file', $arg);
                }
            );
        $locker = $this->factoryLocker($io);

        $file = (new vfsStreamFile(Locker::LOCK_FILE, 0777))->withContent('');
        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($file);

        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertTrue(file_exists($package->path() . '/' . Locker::LOCK_FILE));

        static::assertFalse($locker->isLocked($package));
    }

    /**
     * @test
     */
    public function testIsLockedIsFalseIfHashDiffers(): void
    {
        $lockFile = (new vfsStreamFile(Locker::LOCK_FILE, 0777))->withContent('x');
        $packagesJson = (new vfsStreamFile('package.json', 0777))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);
        $dir->addChild($lockFile);

        $locker = $this->factoryLocker();
        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertTrue(file_exists($package->path() . '/package.json'));
        static::assertTrue(file_exists($package->path() . '/' . Locker::LOCK_FILE));

        static::assertFalse($locker->isLocked($package));
    }

    /**
     * @test
     */
    public function testIsLockedIsFalseBeforeLockAndTrueAfterThat(): void
    {
        $packagesJson = (new vfsStreamFile('package.json', 0777))->withContent('{}');
        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        $locker = $this->factoryLocker();
        $package = $this->factorPackage(['script' => 'test'], $dir->url());

        static::assertFalse($locker->isLocked($package));

        $locker->lock($package);

        static::assertTrue($locker->isLocked($package));
        static::assertTrue($locker->isLocked($package));
    }

    /**
     * @test
     */
    public function testIsLockedIsFalseIfIgnoreIsAll(): void
    {
        $dir = vfsStream::setup('exampleDir1');

        $lockerIgnored = $this->factoryLocker(null, Locker::IGNORE_ALL);
        $lockerNotIgnored = $this->factoryLocker();

        $package = $this->factorPackage(['script' => 'test'], $dir->url());
        $lockerIgnored->lock($package);

        static::assertFalse($lockerIgnored->isLocked($package));
        static::assertTrue($lockerNotIgnored->isLocked($package));
    }

    /**
     * @test
     */
    public function testIsLockedIsFalseIfIgnoreByName(): void
    {
        $dir = vfsStream::setup('exampleDir1', 0777, [
            'one' => [],
            'two' => [],
        ]);

        $io = \Mockery::mock(Io::class);
        $io->allows('isVerbose')->andReturn(false);
        $io
            ->expects('writeVerboseComment')
            ->andReturnUsing(
                static function (string $arg): void {
                    static::assertStringContainsString('ignoring', strtolower($arg));
                    static::assertStringContainsString('test/x-y', $arg);
                }
            );

        $lockerIgnored = $this->factoryLocker($io, 'test/x-*');
        $lockerNotIgnored = $this->factoryLocker();

        $package1 = $this->factorPackage(['script' => 'test'], $dir->url() . '/one', 'test/foo');
        $package2 = $this->factorPackage(['script' => 'test'], $dir->url() . '/two', 'test/x-y');
        $lockerIgnored->lock($package1);
        $lockerIgnored->lock($package2);

        static::assertTrue($lockerIgnored->isLocked($package1));
        static::assertFalse($lockerIgnored->isLocked($package2));

        static::assertTrue($lockerNotIgnored->isLocked($package1));
        static::assertTrue($lockerNotIgnored->isLocked($package2));
    }

    /**
     * @param Io|null $io
     * @param string $ignoreLock
     * @return Locker
     */
    private function factoryLocker(?Io $io = null, string $ignoreLock = ''): Locker
    {
        if ($io === null) {
            $cIo = \Mockery::mock(IOInterface::class);
            $cIo->allows('isVerbose')->andReturn(false);
            $io = Io::new($cIo);
        }

        $finder = PathsFinder::new(new \EmptyIterator(), new Filesystem(), $io, __DIR__);

        return new Locker(
            $io,
            HashBuilder::new($finder, $io),
            $ignoreLock
        );
    }

    /**
     * @param array $settings
     * @param string|null $dir
     * @param string $name
     * @return Asset
     */
    private function factorPackage(
        array $settings,
        ?string $dir = null,
        string $name = 'foo/foo'
    ): Asset {

        $config = Config::forAssetConfigInRoot($settings, ModeResolver::new('', false));

        return Asset::new($name, $config, $dir ?? __DIR__);
    }
}
