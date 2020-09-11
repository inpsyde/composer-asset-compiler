<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Package;

use Composer\IO\IOInterface;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\HashBuilder;
use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class LockerTest extends TestCase
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
        $io->shouldReceive('writeVerboseError')
            ->once()
            ->andReturnUsing(
                static function (string $arg) {
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
     * @param Io|null $io
     * @return Locker
     */
    private function factoryLocker(?Io $io = null): Locker
    {
        return new Locker(
            $io ?? Io::new(\Mockery::mock(IOInterface::class)),
            HashBuilder::new('dev', [])
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
        string $name = 'foo'
    ): Asset {

        $config = Config::forAssetConfigInRoot($settings, EnvResolver::new('', false));

        return Asset::new($name, $config, $dir ?? __DIR__);
    }
}
