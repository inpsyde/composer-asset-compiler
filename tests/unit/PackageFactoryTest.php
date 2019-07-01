<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\Package;
use Inpsyde\AssetsCompiler\PackageFactory;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class PackageFactoryTest extends TestCase
{
    private const DEFAULTS = [
        "dependencies" => "install",
        "script" => "encore prod",
    ];

    public function testCreateWithConfigAllowedPackageLevelAndDefaults()
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, json_decode($json, true), $defaults, true);

        static::assertTrue($package->isValid());
        static::assertTrue($package->update());
        static::assertFalse($package->install());
        static::assertSame(['destroy'], $package->script());
    }

    public function testCreateWithConfigByEnvAllowedPackageLevelAndDefaults()
    {
        $json = <<<'JSON'
{
	"env": {
	    "meh": {
	        "script": ["hello", "world"]
	    },
	    "$default": {
	        "dependencies": "update",
	        "script": "test"
	    }	        
	}
}
JSON;

        $factory = $this->factoryFactory('meh');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, json_decode($json, true), $defaults, true);

        static::assertTrue($package->isValid());
        static::assertFalse($package->update());
        static::assertFalse($package->install());
        static::assertSame(["hello", "world"], $package->script());
    }

    public function testCreateWithConfigNotAllowedPackageLevelAndDefaults()
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, json_decode($json, true), $defaults, false);

        static::assertTrue($package->isValid());
        static::assertTrue($package->update());
        static::assertFalse($package->install());
        static::assertSame(['destroy'], $package->script());
    }

    public function testCreateWithConfigAllowedPackageLevelAndNoDefaults()
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $package = $factory->factory($composerPackage, json_decode($json, true), null, true);

        static::assertTrue($package->isValid());
        static::assertTrue($package->update());
        static::assertFalse($package->install());
        static::assertSame(['destroy'], $package->script());
    }

    public function testCreateWithConfigNotAllowedPackageLevelAndNoDefaults()
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, json_decode($json, true), null, false);

        static::assertTrue($package->isValid());
        static::assertTrue($package->update());
        static::assertFalse($package->install());
        static::assertSame(['destroy'], $package->script());
    }

    public function testCreateWithoutConfigAllowedPackageLevelAndDefaults()
    {
        $factory = $this->factoryFactory();

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn(
            [
                'composer-asset-compiler' => [
                    'script' => 'this_is_nice',
                ],
            ]
        );

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, null, $defaults, true);

        static::assertTrue($package->isValid());
        static::assertSame(['this_is_nice'], $package->script());
    }

    public function testCreateWithoutConfigAllowedPackageLevelAndNoDefaults()
    {
        $factory = $this->factoryFactory();

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn(
            [
                'composer-asset-compiler' => [
                    'script' => 'this_is_nice',
                ],
            ]
        );

        $package = $factory->factory($composerPackage, null, null, true);

        static::assertTrue($package->isValid());
        static::assertSame(['this_is_nice'], $package->script());
    }

    public function testCreateWithoutConfigAllowedPackageLevelByEnvAndDefaults()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn(
            [
                'composer-asset-compiler' => [
                    'env' => [
                        'develop' => [
                            'script' => 'this_is_very_nice',
                        ],
                    ],
                ],
            ]
        );

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, null, $defaults, true);

        static::assertTrue($package->isValid());
        static::assertSame(['this_is_very_nice'], $package->script());
    }

    public function testCreateWithoutConfigNotAllowedPackageLevelAndDefaults()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $composerPackage->shouldReceive('getExtra')->andReturn(
            [
                'composer-asset-compiler' => [
                    'script' => 'this_is_nice',
                ],
            ]
        );

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, null, $defaults, false);

        static::assertTrue($package->isValid());
        static::assertSame(['encore prod'], $package->script());
    }

    public function testCreateWithoutConfigNotAllowedPackageLevelAndNoDefaults()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $composerPackage->shouldReceive('getExtra')->andReturn(
            [
                'composer-asset-compiler' => [
                    'script' => 'this_is_nice',
                ],
            ]
        );

        $package = $factory->factory($composerPackage, null, null, false);

        static::assertFalse($package->isValid());
    }

    public function testCreateWithoutConfigAllowedPackageLevelButNoPackageConfigAndDefaults()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');

        $composerPackage->shouldReceive('getExtra')->andReturn([]);

        $defaults = Package::defaults(self::DEFAULTS);

        $package = $factory->factory($composerPackage, null, $defaults, true);

        static::assertTrue($package->isValid());
        static::assertSame(['encore prod'], $package->script());
    }

    public function testCreateWithoutConfigAllowedPackageLevelButNoPackageConfigAndNoDefaults()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn([]);

        $package = $factory->factory($composerPackage, null, null, true);

        static::assertFalse($package->isValid());
    }

    /**
     * @param string $env
     * @param bool $isDev
     * @return PackageFactory
     */
    private function factoryFactory(string $env = 'test', bool $isDev = true): PackageFactory
    {
        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        /** @var Filesystem $fs */
        $filesystem = \Mockery::mock(Filesystem::class);

        /** @var \Mockery\MockInterface|InstallationManager $im */
        $instManager = \Mockery::mock(InstallationManager::class);
        $instManager->shouldReceive('getInstallPath')
            ->with(\Mockery::type(PackageInterface::class))
            ->andReturn($dir->url());

        $filesystem->shouldReceive('normalizePath')
            ->with($dir->url())
            ->andReturn($dir->url());

        return new PackageFactory(new EnvResolver($env, $isDev), $filesystem, $instManager);
    }
}
