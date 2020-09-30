<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Package;

use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

class RootConfigTest extends TestCase
{
    /**
     * @var Io|\Mockery\MockInterface
     */
    private $io;

    /**
     * @var Filesystem|\Mockery\MockInterface
     */
    private $filesystem;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->io = \Mockery::mock(Io::class);
        $this->filesystem = \Mockery::mock(Filesystem::class)->makePartial();
    }

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
        $this->filesystem
            ->shouldReceive('isSymlinkedDirectory')
            ->once()
            ->with(__DIR__)
            ->andReturn(true);

        $config = $this->factoryConfig($json);

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
        $config = $this->factoryConfig(null, 'test', false, getenv('RESOURCES_DIR'));

        $packages = $config->packagesData();
        $autoDiscover = $config->autoDiscover();
        $autoRun = $config->autoRun();
        [$command, $isDefault] = $config->commands();
        $defaults = $config->defaults();
        $defaultEnv = $config->defaultEnv();
        $stopOnFailure = $config->stopOnFailure();
        $maxProcesses = $config->maxProcesses();
        $processesPoll = $config->processesPoll();

        static::assertIsArray($packages);
        static::assertFalse($autoDiscover);
        static::assertFalse($autoRun);
        static::assertSame('npm', $command);
        static::assertTrue($isDefault);
        static::assertNull($defaults);
        static::assertSame([], $defaultEnv);
        static::assertTrue($stopOnFailure);
        static::assertSame(4, $maxProcesses);
        static::assertSame(100000, $processesPoll);
    }

    /**
     * @param string $json
     * @param string|null $env
     * @param bool $isDev
     * @return RootConfig
     */
    private function factoryConfig(
        ?string $json,
        ?string $env = 'test',
        bool $isDev = false,
        ?string $rootDir = null
    ): RootConfig {

        $root = new RootPackage('company/my-root-package', '1.0', '1.0.0.0');
        $json and $root->setExtra((array)json_decode($json, true));

        return RootConfig::new(
            $root,
            EnvResolver::new($env, $isDev),
            $this->filesystem,
            $rootDir ?? __DIR__
        );
    }
}
