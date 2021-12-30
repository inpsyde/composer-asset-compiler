<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Asset;

use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

class RootConfigUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testBoolSettingsTrue(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "auto-discover": true,
        "auto-run": "true",
        "wipe-node-modules": true,
        "stop-on-failure": "yes",
        "packages": [],
        "defaults": [],
        "commands": null
    }
}
JSON;

        $config = $this->factoryConfig($json);

        static::assertTrue($config->autoDiscover());
        static::assertTrue($config->autoRun());
        static::assertTrue($config->stopOnFailure());
    }

    /**
     * @test
     */
    public function testBoolSettingsFalse(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "auto-discover": false,
        "auto-run": "false",
        "wipe-node-modules": true,
        "stop-on-failure": "no",
        "packages": [],
        "defaults": [],
        "commands": null
    }
}
JSON;

        $config = $this->factoryConfig($json);

        static::assertFalse($config->autoDiscover());
        static::assertFalse($config->autoRun());
        static::assertFalse($config->stopOnFailure());
    }

    /**
     * @test
     */
    public function testStopOnFailureAdvanced(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "stop-on-failure": {
            "env": {
                "$default": true,
                "test": "false"
            }
        }
    }
}
JSON;
        $stopForTest = $this->factoryConfig($json, 'test')->stopOnFailure();
        $stopForProd = $this->factoryConfig($json, 'production')->stopOnFailure();

        static::assertFalse($stopForTest);
        static::assertTrue($stopForProd);
    }

    /**
     * @test
     */
    public function testMaxProcesses(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "max-processes": {
            "env": {
                "$default": 4,
                "test": "10"
            }
        }
    }
}
JSON;
        $forTest = $this->factoryConfig($json, 'test')->maxProcesses();
        $forProd = $this->factoryConfig($json, 'production')->maxProcesses();

        static::assertSame(10, $forTest);
        static::assertSame(4, $forProd);
    }

    /**
     * @test
     */
    public function testProcessesPoll(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "processes-poll": {
            "env": {
                "$default": 100000,
                "test": 500000,
                "low": 500
            }
        }
    }
}
JSON;

        $forTest = $this->factoryConfig($json, 'test')->processesPoll();
        $forProd = $this->factoryConfig($json, 'production')->processesPoll();
        $tooLow = $this->factoryConfig($json, 'low')->processesPoll();

        static::assertSame(500000, $forTest);
        static::assertSame(100000, $forProd);
        static::assertSame(100000, $tooLow);
    }

    /**
     * @test
     */
    public function testWipeNotAllowedForSymlinkedPackages(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "wipe-node-modules": "force"
    }
}
JSON;
        $filesystem = \Mockery::mock(Filesystem::class)->makePartial();
        $filesystem
            ->expects('isSymlinkedDirectory')
            ->with(__DIR__)
            ->andReturn(true);

        /** @var Filesystem $filesystem */
        $config = $this->factoryConfig($json, 'test', __DIR__, $filesystem);

        static::assertFalse($config->isWipeAllowedFor(__DIR__));
        static::assertTrue($config->isWipeAllowedFor(__DIR__ . '/foo'));
    }

    /**
     * @test
     */
    public function testWipeNotAllowedIfNodeModulesExistsAndConfigIsTrue(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "wipe-node-modules": true
    }
}
JSON;
        $config = $this->factoryConfig($json);

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild(new vfsStreamDirectory('node_modules'));

        static::assertFalse($config->isWipeAllowedFor($dir->url()));
        static::assertTrue($config->isWipeAllowedFor($dir->url() . '/foo'));
    }

    /**
     * @test
     */
    public function testWipeAllowedAdvanced(): void
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "wipe-node-modules": {
            "env": {
                "test": true,
                "prod": "force",
                "$default": false
            }
        }
    }
}
JSON;
        $configTest = $this->factoryConfig($json, 'test');
        $configProd = $this->factoryConfig($json, 'prod');
        $configStaging = $this->factoryConfig($json, 'staging');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild(new vfsStreamDirectory('node_modules'));

        static::assertFalse($configTest->isWipeAllowedFor($dir->url()));
        static::assertTrue($configTest->isWipeAllowedFor($dir->url() . '/foo'));

        static::assertTrue($configProd->isWipeAllowedFor($dir->url()));
        static::assertTrue($configProd->isWipeAllowedFor($dir->url() . '/foo'));

        static::assertFalse($configStaging->isWipeAllowedFor($dir->url()));
        static::assertFalse($configStaging->isWipeAllowedFor($dir->url() . '/foo'));
    }

    /**
     * @test
     */
    public function testLoadConfigFromFile(): void
    {
        $config = $this->factoryConfig(null, 'test', getenv('RESOURCES_DIR'));

        $packages = $config->packagesData();
        $autoDiscover = $config->autoDiscover();
        $autoRun = $config->autoRun();
        $defaults = $config->defaults();
        $stopOnFailure = $config->stopOnFailure();
        $maxProcesses = $config->maxProcesses();
        $processesPoll = $config->processesPoll();

        static::assertIsArray($packages);
        static::assertFalse($autoDiscover);
        static::assertFalse($autoRun);
        static::assertNull($defaults);
        static::assertTrue($stopOnFailure);
        static::assertSame(4, $maxProcesses);
        static::assertSame(100000, $processesPoll);
    }

    /**
     * @param string|null $json
     * @param string|null $env
     * @param string|null $rootDir
     * @param Filesystem|null $filesystem
     * @return RootConfig
     */
    private function factoryConfig(
        ?string $json,
        string $env = 'test',
        ?string $rootDir = null,
        ?Filesystem $filesystem = null
    ): RootConfig {

        $package = new RootPackage('company/my-root-package', '1.0.0.0', '1.0');
        $package->setExtra($json ? (array)json_decode($json, true) : []);

        $config = Config::forComposerPackage(
            $package,
            $rootDir ?? __DIR__,
            ModeResolver::new($env, true),
            $filesystem ?? new Filesystem()
        );

        return $config->rootConfig();
    }
}
