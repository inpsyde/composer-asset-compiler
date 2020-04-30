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
use Composer\IO\IOInterface;
use Composer\Package\Package as ComposerPackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\Io;
use Inpsyde\AssetsCompiler\Locker;
use Inpsyde\AssetsCompiler\PackageFactory;
use Inpsyde\AssetsCompiler\PackagesProcessor;
use Inpsyde\AssetsCompiler\ProcessFactory;
use Inpsyde\AssetsCompiler\RootConfig;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;
use Symfony\Component\Process\Process;

class PackagesProcessorTest extends TestCase
{

    public function testProcess()
    {
        /** @var array<string> $installCommands */
        $installCommands = [];
        /** @var array<string> $processesCommands */
        $scriptsCommands = [];

        /** @var RootConfig $config */
        [$rootPackage, $config, $io] = $this->factoryBaseDependencies();
        $processor = $this->factoryProcessor($config, $io, $installCommands, $scriptsCommands);
        $packages = $this->factoryPackages($config, $rootPackage);

        putenv('INNER_ENV=TEST_ME');
        $result = $processor->process(...array_values($packages));
        putenv('INNER_ENV');

        $expected = [
            'inpsyde/root' => [
                'install' => 'yarn install --non-interactive --silent --frozen-lockfile',
                'script' => 'npm run tasks --quiet -- build:test && npm run tests --quiet',
            ],
            'foo/foo' => [
                'install' => 'yarn install --non-interactive --silent --frozen-lockfile',
                'script' => 'npm run build --env.test=test --quiet',
            ],
            'bar/bar-1' => [
                'install' => 'yarn install --non-interactive --silent --frozen-lockfile',
                'script' => 'npm run build --stage --env.test=test --quiet',
            ],
            'bar/bar-2' => [
                'install' => 'yarn install --non-interactive --silent --frozen-lockfile',
                'script' => 'npm run build --stage --env.test=test --quiet',
            ],
            'me/package-one' => [
                'install' => null,
                'script' => 'npm run build production --quiet',
            ],
            'me/package-two' => [
                'install' => 'yarn install --non-interactive --silent --force',
                'script' => 'npm run build staging --quiet',
            ],
            'you/package-two' => [
                'install' => null,
                'script' => 'npm run build --quiet -- TEST_ME override',
            ],
        ];

        static::assertSame(array_keys($expected), array_keys($packages));

        $expectedInstall = array_values(array_filter(array_column($expected, 'install')));
        static::assertSame($expectedInstall, $installCommands);

        $expectedScripts = array_values(array_filter(array_column($expected, 'script')));
        static::assertSame($expectedScripts, $scriptsCommands);

        static::assertTrue($result);
    }

    /**
     * @param array $executedCommands
     * @param array $executedProcessesCommands
     * @return \Inpsyde\AssetsCompiler\PackagesProcessor
     * @noinspection PhpParamsInspection
     */
    private function factoryProcessor(
        RootConfig $config,
        Io $io,
        array &$executedCommands,
        array &$executedProcessesCommands
    ): PackagesProcessor {

        $executor = \Mockery::mock(ProcessExecutor::class);
        $executor->shouldReceive('execute')
            ->andReturnUsing(
                static function (string $command) use (&$executedCommands): int {
                    $executedCommands[] = $command;

                    return 0;
                }
            );

        $commands = $config->commands(__DIR__, $executor);
        $factory = $this->factoryProcessesFactory($executedProcessesCommands);
        $locker = new Locker($io, $config->envResolver()->env());

        return PackagesProcessor::new($io, $config, $commands, $executor, $factory, $locker);
    }

    /**
     * @param \Inpsyde\AssetsCompiler\RootConfig $config
     * @param \Composer\Package\RootPackageInterface $root
     * @return array<string, \Inpsyde\AssetsCompiler\Package>
     */
    private function factoryPackages(RootConfig $config, RootPackageInterface $root): array
    {
        $composerPackages = $this->factoryComposerPackages();
        $finder = $config->packagesFinder();

        return $finder->find(
            new ArrayRepository($composerPackages),
            $root,
            $this->factoryProcessFactory($config)
        );
    }

    /**
     * @return array{0:RootPackage, 1:Config, 2:Io}
     */
    private function factoryBaseDependencies(): array
    {
        $io = $this->factoryIo(IOInterface::VERY_VERBOSE, false);

        $extra = json_decode($this->composerJson(), true);
        $root = new RootPackage('inpsyde/root', '1.0', '1.0.0.0');
        $root->setExtra($extra);

        $config = new RootConfig(
            $root,
            new EnvResolver('staging', true),
            new Filesystem(),
            $io
        );

        return [$root, $config, $io];
    }

    /**
     * @param array $processCommands
     * @return \Inpsyde\AssetsCompiler\ProcessFactory
     */
    private function factoryProcessesFactory(array &$processCommands): ProcessFactory
    {
        $factory = function (string $cmd, ?string $cwd = null) use (&$processCommands): Process {
            $processCommands[] = $cmd;

            return $this->mockSymfonyProcess();
        };

        return new ProcessFactory($factory);
    }

    /**
     * @noinspection PhpParamsInspection
     */
    private function factoryProcessFactory(RootConfig $config): PackageFactory
    {
        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');
        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        /** @var \Mockery\MockInterface|InstallationManager $manager */
        $manager = \Mockery::mock(InstallationManager::class);
        $manager->shouldReceive('getInstallPath')
            ->with(\Mockery::type(PackageInterface::class))
            ->andReturn($dir->url());

        return new PackageFactory(
            $config->envResolver(),
            $config->filesystem(),
            $manager,
            $dir->url()
        );
    }

    /**
     * @noinspection PhpParamsInspection
     */
    private function mockSymfonyProcess(): Process
    {
        $process = \Mockery::mock(Process::class);

        $started = null;

        $process->shouldReceive('start')
            ->once()
            ->with(\Mockery::type(\Closure::class))
            ->andReturnUsing(
                static function () use (&$started) {
                    $started = microtime(true);
                }
            );

        $process->shouldReceive('isRunning')
            ->atLeast()->once()
            ->andReturnUsing(
                static function () use (&$started): bool {
                    return $started && ((microtime(true) - $started) < 0.1);
                }
            );

        $process->shouldReceive('isSuccessful')
            ->atLeast()->once()
            ->andReturnUsing(
                static function () use (&$started): bool {
                    if ($started === null) {
                        throw new \Exception('isSuccessful should not be called without starting.');
                    }

                    return true;
                }
            );

        return $process;
    }

    /**
     * @return array<int, \Composer\Package\PackageInterface>
     */
    private function factoryComposerPackages(): array
    {
        $fooBar = new ComposerPackage('foo/foo', '1.0', '1.0.0.0');
        $barBar = new ComposerPackage('bar/bar-1', '1.0', '1.0.0.0');
        $barBaz = new ComposerPackage('bar/bar-2', '1.0', '1.0.0.0');

        $me1 = new ComposerPackage('me/package-one', '1.0', '1.0.0.0');
        $me1->setExtra(
            [
                'composer-asset-compiler' => [
                    'default-env' => [
                        'ASSETS_ARGS' => 'production',
                    ],
                    'script' => 'build ${ASSETS_ARGS}',
                ],
            ]
        );

        $me2 = new ComposerPackage('me/package-two', '1.0', '1.0.0.0');
        $me2->setExtra(
            [
                'composer-asset-compiler' => [
                    'env' => [
                        '$default' => [
                            'script' => 'build --default',
                        ],
                        'staging' => [
                            'dependencies' => 'update',
                            'script' => 'build staging',
                        ],
                        'production' => [
                            'script' => 'build production',
                        ],
                    ],
                ],
            ]
        );

        $you1 = new ComposerPackage('you/package-one', '1.0', '1.0.0.0');

        $you2 = new ComposerPackage('you/package-two', '1.0', '1.0.0.0');
        $you2->setExtra(
            [
                'composer-asset-compiler' => [
                    'script' => 'build -- ${INNER_ENV} ${ASSETS_ARGS}',
                    'default-env' => [
                        'INNER_ENV' => 'dev',
                        'ASSETS_ARGS' => 'override',
                    ],
                ],
            ]
        );

        return [$fooBar, $barBar, $barBaz, $me1, $me2, $you1, $you2];
    }

    /**
     * @return string
     */
    private function composerJson(): string
    {
        $json = <<<'JSON'
{
    "composer-asset-compiler": {
        "env": {
            "staging": {
                "dependencies": "install",
                "script": [
                    "tasks -- build:${GULP_ENV}",
                    "tests"
                ]
            }
        },
        "default-env": {
            "ASSETS_ARGS": "--env.test=test",
            "GULP_ENV": "test"
        },
        "commands": {
            "env": {
                "staging": {
                    "dependencies": {
                        "install": "yarn install --non-interactive --silent --frozen-lockfile",
                        "update": "yarn install --non-interactive --silent --force"
                    },
                    "script": "npm run %s --quiet"
                },
                "production": "npm"
            }
        },
        "packages": {
            "foo/foo": {
                "dependencies": "install",
                "script": "build ${ASSETS_ARGS}"
            },
            "bar/*": {
                "env": {
                    "staging": {
                        "dependencies": "install",
                        "script": "build --stage ${ASSETS_ARGS}"
                    },
                    "$default": {
                        "script": "build --prod ${ASSETS_ARGS}"
                    }
                }
            }
        }
    }
}
JSON;

        return trim($json);
    }
}
