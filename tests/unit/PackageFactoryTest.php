<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Commands;
use Inpsyde\AssetsCompiler\Defaults;
use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\PackageConfig;
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

    /** @noinspection PhpParamsInspection */
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

        $package = $factory->attemptFactory(
            $composerPackage,
            $this->factoryConfig($json),
            $this->factoryDefault()
        );

        static::assertTrue($package->isValid());
        static::assertTrue($package->isUpdate());
        static::assertFalse($package->isInstall());
        static::assertSame(['destroy'], $package->script());
    }

    /** @noinspection PhpParamsInspection */
    public function testCreateWithConfigByEnvAllowedPackageLevelAndDefaults()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
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
}
JSON;

        $factory = $this->factoryFactory('meh');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn(json_decode($json, true));

        $package = $factory->attemptFactory(
            $composerPackage,
            null,
            $this->factoryDefault()
        );

        static::assertTrue($package->isValid());
        static::assertFalse($package->isUpdate());
        static::assertFalse($package->isInstall());
        static::assertSame(["hello", "world"], $package->script());
    }

    /** @noinspection PhpParamsInspection */
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

        $package = $factory->attemptFactory(
            $composerPackage,
            $this->factoryConfig($json),
            $this->factoryDefault()
        );

        static::assertTrue($package->isValid());
        static::assertTrue($package->isUpdate());
        static::assertFalse($package->isInstall());
        static::assertSame(['destroy'], $package->script());
    }

    /** @noinspection PhpParamsInspection */
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

        $package = $factory->attemptFactory(
            $composerPackage,
            $this->factoryConfig($json),
            Defaults::empty()
        );

        static::assertTrue($package->isValid());
        static::assertTrue($package->isUpdate());
        static::assertFalse($package->isInstall());
        static::assertSame(['destroy'], $package->script());
    }

    /** @noinspection PhpParamsInspection */
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

        $package = $factory->attemptFactory(
            $composerPackage,
            $this->factoryConfig($json),
            Defaults::empty()
        );

        static::assertTrue($package->isValid());
        static::assertTrue($package->isUpdate());
        static::assertFalse($package->isInstall());
        static::assertSame(['destroy'], $package->script());
    }

    /** @noinspection PhpParamsInspection */
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

        $package = $factory->attemptFactory($composerPackage, null, $this->factoryDefault());

        static::assertTrue($package->isValid());
        static::assertSame(['this_is_nice'], $package->script());
    }

    /** @noinspection PhpParamsInspection */
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

        $package = $factory->attemptFactory($composerPackage, null, Defaults::empty());

        static::assertTrue($package->isValid());
        static::assertSame(['this_is_nice'], $package->script());
    }

    /** @noinspection PhpParamsInspection */
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

        $package = $factory->attemptFactory($composerPackage, null, $this->factoryDefault());

        static::assertTrue($package->isValid());
        static::assertSame(['this_is_very_nice'], $package->script());
    }

    /** @noinspection PhpParamsInspection */
    public function testCreateWithoutConfigAllowedPackageLevelButNoPackageConfigAndDefaults()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn([]);

        $defaults = $this->factoryDefault();

        $package = $factory->attemptFactory($composerPackage, null, $defaults);

        static::assertNull($package);
    }

    /** @noinspection PhpParamsInspection */
    public function testCreateWithoutConfigAllowedPackageLevelButNoPackageConfigAndNoDefaults()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn([]);

        $package = $factory->attemptFactory($composerPackage, null, Defaults::empty());

        static::assertNull($package);
    }

    /** @noinspection PhpParamsInspection */
    public function testCreateWithoutConfigAllowedPackageLevelByEnvAndPackageEnv()
    {
        $factory = $this->factoryFactory('develop');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $composerPackage = \Mockery::mock(PackageInterface::class);
        $composerPackage->shouldReceive('getName')->andReturn('test/test-package');
        $composerPackage->shouldReceive('getExtra')->andReturn(
            [
                'composer-asset-compiler' => [
                    'default-env' => [
                        'ENCORE_ENV' => 'prod',
                    ],
                    'env' => [
                        'develop' => [
                            'script' => 'encore ${ENCORE_ENV}',
                        ],
                        'prod' => [
                            'script' => 'my-script',
                        ],
                    ],
                ],
            ]
        );

        $package = $factory->attemptFactory($composerPackage, null, Defaults::empty());

        static::assertTrue($package->isValid());
        static::assertSame('prod', $package->env()['ENCORE_ENV']);

        $scripts = $package->script();
        static::assertSame(['encore ${ENCORE_ENV}'], $scripts);
        $script = array_pop($scripts);

        $commandsNoEnv = Commands::fromDefault('yarn', []);
        static::assertSame('yarn encore prod', $commandsNoEnv->scriptCmd($script, $package->env()));

        $commandsWithEnv = Commands::fromDefault('yarn', ['ENCORE_ENV' => 'dev']);
        static::assertSame(
            'yarn encore prod',
            $commandsWithEnv->scriptCmd($script, $package->env())
        );
    }

    /** @noinspection PhpParamsInspection */
    public function testForRootPackage()
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "test"
}
JSON;
        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $rootPackage = \Mockery::mock(RootPackageInterface::class);
        $rootPackage->shouldReceive('getName')->andReturn('test/root-package');

        /** @var PackageInterface|\Mockery\MockInterface $composerPackage */
        $noRootPackage = \Mockery::mock(RootPackageInterface::class);
        $noRootPackage->shouldReceive('getName')->andReturn('test/some-package');

        $rootDir = vfsStream::setup('rootDir');
        $rootDir->addChild((new vfsStreamFile('package.json'))->withContent('{}'));
        $rootPath = $rootDir->url();

        $fromRoot = $factory->attemptFactory(
            $rootPackage,
            $this->factoryConfig($json),
            $this->factoryDefault()
        );

        $fromDep = $factory->attemptFactory(
            $noRootPackage,
            $this->factoryConfig($json),
            $this->factoryDefault()
        );

        static::assertTrue($fromRoot->isValid());
        static::assertTrue($fromRoot->isUpdate());
        static::assertFalse($fromRoot->isInstall());
        static::assertSame(['test'], $fromRoot->script());

        static::assertTrue($fromDep->isValid());
        static::assertTrue($fromDep->isUpdate());
        static::assertFalse($fromDep->isInstall());
        static::assertSame(['test'], $fromDep->script());

        static::assertSame($rootPath, $fromRoot->path());
        static::assertNotSame($fromDep, $fromDep->path());
    }

    /**
     * @param array|string[] $config
     * @return \Inpsyde\AssetsCompiler\Defaults
     */
    private function factoryDefault(array $config = self::DEFAULTS): Defaults
    {
        $conf = PackageConfig::forRawPackageData($config, new EnvResolver('', false));

        return Defaults::new($conf);
    }

    /**
     * @param string $env
     * @param bool $isDev
     * @return PackageFactory
     *
     * @noinspection PhpParamsInspection
     */
    private function factoryFactory(
        string $env = 'test',
        bool $isDev = true,
        ?string $rootPath = null
    ): PackageFactory {

        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        if (!$rootPath) {
            $rootDir = vfsStream::setup('rootDir');
            $rootDir->addChild($packagesJson);
            $rootPath = $rootDir->url();
        }

        $rootDir = vfsStream::setup('rootDir');
        $rootDir->addChild($packagesJson);

        /** @var \Mockery\MockInterface|InstallationManager $im */
        $manager = \Mockery::mock(InstallationManager::class);
        $manager->shouldReceive('getInstallPath')
            ->with(\Mockery::type(PackageInterface::class))
            ->andReturn($dir->url());

        $filesystem = new Filesystem();

        return new PackageFactory(new EnvResolver($env, $isDev), $filesystem, $manager, $rootPath);
    }

    /**
     * @param string $json
     * @return \Inpsyde\AssetsCompiler\PackageConfig
     */
    private function factoryConfig(string $json): PackageConfig
    {
        $resolver = new EnvResolver('', false);

        return PackageConfig::forRawPackageData(json_decode($json, true), $resolver);
    }
}
