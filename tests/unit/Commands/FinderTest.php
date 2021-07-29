<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Commands;

use Composer\Installer\InstallationManager;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Defaults;
use Inpsyde\AssetsCompiler\Asset\Factory;
use Inpsyde\AssetsCompiler\Commands\Finder;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @runTestsInSeparateProcesses
 */
class FinderTest extends TestCase
{
    /**
     * @test
     */
    public function testFindYarnViaDiscover(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('yarn'));

        $commands = $finder->find($this->factoryRootConfig());
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('yarn', $commands->installCmd($io));
        static::assertStringContainsString('yarn', $commands->updateCmd($io));
        static::assertStringContainsString('yarn run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindNpmViaDiscover(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('npm'));

        $commands = $finder->find($this->factoryRootConfig());
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('npm', $commands->installCmd($io));
        static::assertStringContainsString('npm', $commands->updateCmd($io));
        static::assertStringContainsString('npm run run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindNpmBecauseLock(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $commands = $finder->find($this->factoryRootConfig([], getenv('RESOURCES_DIR') . '/01'));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('npm', $commands->installCmd($io));
        static::assertStringContainsString('npm', $commands->updateCmd($io));
        static::assertStringContainsString('npm run run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindNpmBecauseLockFallbacksToYarnIfNoNpm(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('yarn'));

        $commands = $finder->find($this->factoryRootConfig([], getenv('RESOURCES_DIR') . '/01'));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('yarn', $commands->installCmd($io));
        static::assertStringContainsString('yarn', $commands->updateCmd($io));
        static::assertStringContainsString('yarn run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindYarnBecauseAssetConfig(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $commands = $finder->findForAsset($this->factoryAsset(getenv('RESOURCES_DIR') . '/02'));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('yarn', $commands->installCmd($io));
        static::assertStringContainsString('yarn', $commands->updateCmd($io));
        static::assertStringContainsString('yarn run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindNpmBecauseConfigInExternalFile(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $commands = $finder->find($this->factoryRootConfig([], getenv('RESOURCES_DIR')));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('npm', $commands->installCmd($io));
        static::assertStringContainsString('npm', $commands->updateCmd($io));
        static::assertStringContainsString('npm run run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindNpmBecauseAssetConfigInExternalFile(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $commands = $finder->findForAsset($this->factoryAsset(getenv('RESOURCES_DIR')));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('npm', $commands->installCmd($io));
        static::assertStringContainsString('npm', $commands->updateCmd($io));
        static::assertStringContainsString('npm run run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindNothingViaDiscover(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor(null));

        $this->expectExceptionMessageMatches('/not found/i');

        $finder->find($this->factoryRootConfig());
    }

    /**
     * @test
     */
    public function testFindYarnViaDefault(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $commands = $finder->find($this->factoryRootConfig([RootConfig::COMMANDS => 'yarn']));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('yarn', $commands->installCmd($io));
        static::assertStringContainsString('yarn', $commands->updateCmd($io));
        static::assertStringContainsString('yarn run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindNpmViaDefault(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $commands = $finder->find($this->factoryRootConfig([RootConfig::COMMANDS => 'NPM']));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('npm', $commands->installCmd($io));
        static::assertStringContainsString('npm', $commands->updateCmd($io));
        static::assertStringContainsString('npm run run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindCustomValid(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $config = $this->factoryRootConfig([
            RootConfig::COMMANDS => [
                'dependencies' => [
                    'install' => 'custom install',
                    'update' => 'custom update',
                ],
                'script' => 'npm script %s',
            ],
        ]);

        $commands = $finder->find($config);
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertSame('custom install', $commands->installCmd($io));
        static::assertSame('custom update', $commands->updateCmd($io));
        static::assertStringContainsString('npm script run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindCustomInvalid(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor(null));

        $config = $this->factoryRootConfig([
            RootConfig::COMMANDS => [
                'x' => null,
                'script' => 'custom script %s',
            ],
        ]);

        $this->expectExceptionMessageMatches('/not found/i');
        $finder->find($config);
    }

    /**
     * @test
     */
    public function testFindCustomInvalidFallbacks(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('npm'));

        $config = $this->factoryRootConfig([
            RootConfig::COMMANDS => [
                'x' => null,
                'script' => 'custom script %s',
            ],
        ]);

        $commands = $finder->find($config);
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('npm', $commands->installCmd($io));
        static::assertStringContainsString('npm', $commands->updateCmd($io));
        static::assertStringContainsString('npm run run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testInvalidSettingsSuccessfullyFallbacksToDiscover(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('npm'));

        $commands = $finder->find($this->factoryRootConfig([RootConfig::COMMANDS => 'meh']));
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertStringContainsString('npm', $commands->installCmd($io));
        static::assertStringContainsString('npm', $commands->updateCmd($io));
        static::assertStringContainsString('npm run run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testInvalidSettingsFallbacksToDiscoverAndFails(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor(null));

        $this->expectExceptionMessageMatches('/not found/i');

        $finder->find($this->factoryRootConfig([RootConfig::COMMANDS => 'meh']));
    }

    /**
     * @param array $extra
     * @param string|null $dir
     * @return RootConfig
     */
    public function factoryRootConfig(array $extra = [], ?string $dir = null): RootConfig
    {
        $package = new RootPackage('test', '1.0.0.0', '1.0');
        $package->setExtra([Config::EXTRA_KEY => $extra]);

        return RootConfig::new(
            $package,
            EnvResolver::new('test', true),
            new Filesystem(),
            $dir ?? __DIR__
        );
    }

    /**
     * @param array $extra
     * @param string|null $dir
     * @return RootConfig
     */
    public function factoryAsset(string $dir): Asset
    {
        $data = json_decode(file_get_contents("{$dir}/composer.json"), true);
        if (empty($data['version'])) {
            $data['version'] = '1.0';
        }
        $package = (new ArrayLoader())->load($data, RootPackage::class);

        $manager = \Mockery::mock(InstallationManager::class);
        $manager->shouldReceive('getInstallPath')->with($package)->andReturn($dir);

        $assetFactory = Factory::new(
            EnvResolver::new('test', true),
            new Filesystem(),
            $manager,
            $dir
        );

        return $assetFactory->attemptFactory($package, null, Defaults::empty());
    }

    /**
     * @param ProcessExecutor|null $executor
     * @return Finder
     */
    private function factoryFinder(?ProcessExecutor $executor = null): Finder
    {
        return Finder::new(
            $executor ?? new ProcessExecutor(),
            EnvResolver::new('test', true),
            new Filesystem(),
            $this->factoryIo(OutputInterface::VERBOSITY_VERBOSE),
            []
        );
    }

    /**
     * @param string|null $which
     * @return ProcessExecutor
     */
    private function factoryExecutor(?string $which): ProcessExecutor
    {
        $executor = \Mockery::mock(ProcessExecutor::class);
        $executor
            ->shouldReceive('execute')
            ->andReturnUsing(
                static function (string $discover) use ($which): int {
                    if ($which === null) {
                        return 1;
                    }

                    if ($which === '*') {
                        return 0;
                    }

                    return strpos($discover, $which) === false ? 1 : 0;
                }
            );

        return $executor;
    }
}
