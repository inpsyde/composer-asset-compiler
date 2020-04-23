<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Config;
use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\Io;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

class ConfigTest extends TestCase
{
    /**
     * @var Io|\Mockery\MockInterface
     */
    private $io;

    /**
     * @var Filesystem|\Mockery\MockInterface
     */
    private $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->io = \Mockery::mock(Io::class);
        $this->filesystem = \Mockery::mock(Filesystem::class)->makePartial();
    }

    public function testBoolSettingsTrue()
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

    public function testBoolSettingsFalse()
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

    public function testCommandsCreatesYarnFromDefault()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "defaults": [],
        "commands": "yarn"
    }
}
JSON;
        $config = $this->factoryConfig($json);

        static::assertSame('yarn', $config->commands(__DIR__)->installCmd($this->factoryIo()));
    }

    public function testCommandsCreatesNpmFromDefault()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "defaults": [],
        "commands": "npm"
    }
}
JSON;
        $config = $this->factoryConfig($json);

        $io = $this->factoryIo();
        static::assertSame('npm install', $config->commands(__DIR__)->installCmd($io));
    }

    public function testCommandsAdvanced()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "defaults": [],
        "commands": {
            "env": {
                "$default": "npm",
                "local": "yarn",
                "test": {
                    "dependencies": {
                        "install": "foo --install",
                        "update": "bar --update"
                    },
                    "script": "baz %s --run"
                }
            }
        }
    }
}
JSON;
        $configForTest = $this->factoryConfig($json, 'test')->commands(__DIR__);
        $configForProd = $this->factoryConfig($json, 'production')->commands(__DIR__);
        $configForLocal = $this->factoryConfig($json, 'local')->commands(__DIR__);

        static::assertSame('foo --install', $configForTest->installCmd($this->factoryIo()));
        static::assertSame('bar --update', $configForTest->updateCmd($this->factoryIo()));
        static::assertSame('baz x --run', $configForTest->scriptCmd('x'));

        static::assertSame('npm install', $configForProd->installCmd($this->factoryIo()));

        static::assertSame('yarn', $configForLocal->installCmd($this->factoryIo()));
    }

    /** @noinspection PhpParamsInspection */
    public function testCommandsFromBadDefaults()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "defaults": [],
        "commands": "wrong"
    }
}
JSON;
        $exec = \Mockery::mock(ProcessExecutor::class);
        $exec->shouldReceive('execute')->andReturn(1);

        $this->io
            ->shouldReceive('writeError')
            ->andReturnUsing(
                function (string $msg): void {
                    static::assertStringContainsString('not valid, trying to auto-discover', $msg);
                }
            );

        $config = $this->factoryConfig($json)->commands(__DIR__, $exec);

        static::assertFalse($config->isValid());
    }

    public function testDefaultsAdvanced()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "defaults": {
            "env": {
                "$default": {
                    "dependencies": "update"
                },
                "invalid": {
                    "foo": "bar",
                    "meh": true
                },
                "test": {
                    "dependencies": "install",
                    "script": ["foo", "bar"]
                }
            }
        }
    }
}
JSON;
        $defaultsForTest = $this->factoryConfig($json, 'test')->defaults();
        $defaultsForProd = $this->factoryConfig($json, 'production')->defaults();
        $defaultsInvalid = $this->factoryConfig($json, 'invalid')->defaults();

        static::assertTrue($defaultsForTest->isInstall());
        static::assertTrue($defaultsForProd->isUpdate());
        static::assertNull($defaultsInvalid);
    }

    public function testStopOnFailureAdvanced()
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

    public function testMaxProcesses()
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

    public function testProcessesPoll()
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

    public function testWipeNotAllowedForSymlinkedPackages()
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

        $config =  $this->factoryConfig($json);

        static::assertFalse($config->wipeAllowed(__DIR__));
        static::assertTrue($config->wipeAllowed(__DIR__ . '/foo'));
    }

    public function testWipeNotAllowedIfNodeModulesExistsAndConfigIsTrue()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "wipe-node-modules": true
    }
}
JSON;
        $config =  $this->factoryConfig($json);

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild(new vfsStreamDirectory('node_modules'));

        static::assertFalse($config->wipeAllowed($dir->url()));
        static::assertTrue($config->wipeAllowed($dir->url() . '/foo'));
    }

    public function testWipeAllowedAdvanced()
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
        $configTest =  $this->factoryConfig($json, 'test');
        $configProd =  $this->factoryConfig($json, 'prod');
        $configStaging =  $this->factoryConfig($json, 'staging');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild(new vfsStreamDirectory('node_modules'));

        static::assertFalse($configTest->wipeAllowed($dir->url()));
        static::assertTrue($configTest->wipeAllowed($dir->url() . '/foo'));

        static::assertTrue($configProd->wipeAllowed($dir->url()));
        static::assertTrue($configProd->wipeAllowed($dir->url() . '/foo'));

        static::assertFalse($configStaging->wipeAllowed($dir->url()));
        static::assertFalse($configStaging->wipeAllowed($dir->url() . '/foo'));
    }

    public function testDefaultEnv()
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "packages": [],
        "default-env": {
            "FOO": "FOO",
            "BAR_2": "BAR_2",
            " bad": "bad",
            "1no1": "no",
            "OK": "this_is_ok",
            "n-o": "this_is_not"
        }
    }
}
JSON;
        $env =  $this->factoryConfig($json)->defaultEnv();

        static::assertSame('FOO', $env['FOO']);
        static::assertSame('BAR_2', $env['BAR_2']);
        static::assertSame('this_is_ok', $env['OK']);
        static::assertArrayNotHasKey(' bad', $env);
        static::assertArrayNotHasKey('bad', $env);
        static::assertArrayNotHasKey('1no1', $env);
        static::assertArrayNotHasKey('n-o', $env);
    }

    /**
     * @param string $json
     * @param string|null $env
     * @param bool $isDev
     * @return Config
     */
    private function factoryConfig(string $json, ?string $env = 'test', bool $isDev = false): Config
    {
        $root = new RootPackage('company/my-root-package', '1.0', '1.0.0.0');
        $root->setExtra((array)json_decode($json, true));

        return new Config(
            $root,
            new EnvResolver($env, $isDev),
            $this->filesystem,
            $this->io
        );
    }
}
