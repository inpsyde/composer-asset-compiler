<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Asset;

use Composer\Installer\InstallationManager;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Defaults;
use Inpsyde\AssetsCompiler\Asset\Factory;
use Inpsyde\AssetsCompiler\PackageManager\PackageManager;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class FactoryUnitTest extends UnitTestCase
{
    private const DEFAULTS = [
        "dependencies" => "install",
        "script" => "encore prod",
    ];

    /**
     * @test
     */
    public function testCreateWithConfigAllowedPackageLevelAndDefaults(): void
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        $package = new Package('test/test-package', '1.0.0.0', 'v1');

        $asset = $factory->attemptFactory(
            $package,
            $this->factoryConfig($json),
            $this->factoryDefault()
        );

        static::assertTrue($asset->isValid());
        static::assertTrue($asset->isUpdate());
        static::assertFalse($asset->isInstall());
        static::assertSame(['destroy'], $asset->script());
    }

    /**
     * @test
     */
    public function testCreateWithConfigByEnvAllowedPackageLevelAndDefaults(): void
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

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(json_decode($json, true));

        $asset = $factory->attemptFactory(
            $package,
            null,
            $this->factoryDefault()
        );

        static::assertTrue($asset->isValid());
        static::assertFalse($asset->isUpdate());
        static::assertTrue($asset->isInstall());
        static::assertSame(["hello", "world"], $asset->script());
        static::assertSame('v1', $asset->version());
    }

    /**
     * @test
     */
    public function testCreateWithConfigNotAllowedPackageLevelAndDefaults(): void
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(json_decode($json, true));

        $asset = $factory->attemptFactory(
            $package,
            $this->factoryConfig($json),
            $this->factoryDefault()
        );

        static::assertTrue($asset->isValid());
        static::assertTrue($asset->isUpdate());
        static::assertFalse($asset->isInstall());
        static::assertSame(['destroy'], $asset->script());
    }

    /**
     * @test
     */
    public function testCreateWithConfigAllowedPackageLevelAndNoDefaults()
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        $package = new Package('test/test-package', '1.0.0.0', 'v1');

        $asset = $factory->attemptFactory(
            $package,
            $this->factoryConfig($json),
            Defaults::empty()
        );

        static::assertTrue($asset->isValid());
        static::assertTrue($asset->isUpdate());
        static::assertFalse($asset->isInstall());
        static::assertSame(['destroy'], $asset->script());
    }

    /**
     * @test
     */
    public function testCreateWithConfigNotAllowedPackageLevelAndNoDefaults(): void
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "destroy"
}
JSON;
        $package = new Package('test/test-package', '1.0.0.0', 'v1');

        $asset = $factory->attemptFactory(
            $package,
            $this->factoryConfig($json),
            Defaults::empty()
        );

        static::assertTrue($asset->isValid());
        static::assertTrue($asset->isUpdate());
        static::assertFalse($asset->isInstall());
        static::assertSame(['destroy'], $asset->script());
    }

    /**
     * @test
     */
    public function testCreateWithoutConfigAllowedPackageLevelAndDefaults(): void
    {
        $factory = $this->factoryFactory();

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(
            [
                'composer-asset-compiler' => [
                    'script' => 'this_is_nice',
                ],
            ]
        );

        $asset = $factory->attemptFactory($package, null, $this->factoryDefault());

        static::assertTrue($asset->isValid());
        static::assertSame(['this_is_nice'], $asset->script());
    }

    /**
     * @test
     */
    public function testCreateWithoutConfigAllowedPackageLevelAndNoDefaults(): void
    {
        $factory = $this->factoryFactory();

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(
            [
                'composer-asset-compiler' => [
                    'script' => 'this_is_nice',
                ],
            ]
        );

        $asset = $factory->attemptFactory($package, null, Defaults::empty());

        static::assertTrue($asset->isValid());
        static::assertSame(['this_is_nice'], $asset->script());
    }

    /**
     * @test
     */
    public function testCreateWithoutConfigAllowedPackageLevelByEnvAndDefaults(): void
    {
        $factory = $this->factoryFactory('develop');

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(
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

        $asset = $factory->attemptFactory($package, null, $this->factoryDefault());

        static::assertTrue($asset->isValid());
        static::assertSame(['this_is_very_nice'], $asset->script());
    }

    /**
     * @test
     */
    public function testCreateWithoutConfigAllowedPackageLevelButNoPackageConfigAndDefaults(): void
    {
        $factory = $this->factoryFactory('develop');

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra([]);

        $defaults = $this->factoryDefault();

        $asset = $factory->attemptFactory($package, null, $defaults);

        static::assertNull($asset);
    }

    /**
     * @test
     */
    public function testCreateWithoutConfigAllowedPackageLevelButNoPackageConfigAndNoDefaults(): void
    {
        $factory = $this->factoryFactory('develop');

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra([]);

        $asset = $factory->attemptFactory($package, null, Defaults::empty());

        static::assertNull($asset);
    }

    /**
     * @test
     */
    public function testCreateWithoutConfigAllowedPackageLevelByEnvAndPackageEnv(): void
    {
        $factory = $this->factoryFactory('develop');

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(
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

        $asset = $factory->attemptFactory($package, null, Defaults::empty());

        static::assertTrue($asset->isValid());
        static::assertSame('prod', $asset->env()['ENCORE_ENV']);

        $scripts = $asset->script();
        static::assertSame(['encore ${ENCORE_ENV}'], $scripts);
        $script = array_pop($scripts);

        $yarnNoEnv = PackageManager::fromDefault('yarn', []);
        static::assertSame('yarn encore prod', $yarnNoEnv->scriptCmd($script, $asset->env()));

        $yarnWithEnv = PackageManager::fromDefault('yarn', ['ENCORE_ENV' => 'dev']);
        static::assertSame(
            'yarn encore prod',
            $yarnWithEnv->scriptCmd($script, $asset->env())
        );
    }

    /**
     * @test
     */
    public function testCreateWithOnlyScript(): void
    {
        $factory = $this->factoryFactory('develop');

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(['composer-asset-compiler' => "build"]);

        $asset = $factory->attemptFactory($package, null, Defaults::empty());

        static::assertTrue($asset->isValid());
        static::assertSame(['build'], $asset->script());
        static::assertTrue($asset->isInstall());
    }

    /**
     * @test
     */
    public function testCreateWithScriptAnNoDependencies(): void
    {
        $factory = $this->factoryFactory('develop');

        $package = new Package('test/test-package', '1.0.0.0', 'v1');
        $package->setExtra(
            [
                'composer-asset-compiler' => [
                    "dependencies" => "none",
                    "script" => "build",
                ],
            ]
        );

        $asset = $factory->attemptFactory($package, null, Defaults::empty());

        static::assertTrue($asset->isValid());
        static::assertSame(['build'], $asset->script());
        static::assertFalse($asset->isInstall());
        static::assertFalse($asset->isUpdate());
    }

    /**
     * @test
     */
    public function testForRootPackage(): void
    {
        $factory = $this->factoryFactory();

        $json = <<<'JSON'
{
	"dependencies": "update",
	"script": "test"
}
JSON;
        $rootPackage = new RootPackage('test/root-package', '1.0.0.0', 'v1');

        $noRootPackage = new Package('test/some-package', '1.0.0.0', 'v1');

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
     * @param string $env
     * @param bool $isDev
     * @param string|null $rootPath
     * @return Factory
     */
    private function factoryFactory(string $env = 'test'): Factory
    {
        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        $rootDir = vfsStream::setup('rootDir');
        $rootDir->addChild($packagesJson);
        $rootPath = $rootDir->url();

        $rootDir = vfsStream::setup('rootDir');
        $rootDir->addChild($packagesJson);

        /** @var \Mockery\MockInterface|InstallationManager $im */
        $manager = \Mockery::mock(InstallationManager::class);
        $manager->allows('getInstallPath')
            ->with(\Mockery::type(PackageInterface::class))
            ->andReturns($dir->url());

        $filesystem = new Filesystem();

        return Factory::new(ModeResolver::new($env, true), $filesystem, $manager, $rootPath, []);
    }

    /**
     * @param array|string[] $config
     * @return Defaults
     */
    private function factoryDefault(array $config = self::DEFAULTS): Defaults
    {
        $conf = Config::forAssetConfigInRoot($config, ModeResolver::new('', false));

        return Defaults::new($conf);
    }

    /**
     * @param string $json
     * @return Config
     */
    private function factoryConfig(string $json): Config
    {
        $resolver = ModeResolver::new('', false);

        return Config::forAssetConfigInRoot(json_decode($json, true), $resolver);
    }
}
