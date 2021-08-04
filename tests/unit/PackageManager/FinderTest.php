<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\PackageManager;

use Composer\Installer\InstallationManager;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Defaults;
use Inpsyde\AssetsCompiler\Asset\Factory;
use Inpsyde\AssetsCompiler\PackageManager\Finder;
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

        $commands = $finder->findForConfig($this->factoryConfig(), 'x/x', __DIR__);
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

        $commands = $finder->findForConfig($this->factoryConfig(), 'x/x', __DIR__);
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

        $config = $this->factoryConfig([], getenv('RESOURCES_DIR') . '/01');
        $commands = $finder->findForConfig($config, 'x/x', getenv('RESOURCES_DIR') . '/01');
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

        $config = $this->factoryConfig([], getenv('RESOURCES_DIR') . '/01');
        $commands = $finder->findForConfig($config, 'x/x', getenv('RESOURCES_DIR') . '/01');
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

        $config = $this->factoryConfig([], getenv('RESOURCES_DIR'));
        $commands = $finder->findForConfig($config, 'x/x', getenv('RESOURCES_DIR'));
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

        $commands = $finder->findForAsset($this->factoryAsset(getenv('RESOURCES_DIR') . '/04'));
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

        $finder->findForConfig($this->factoryConfig(), 'x/x', __DIR__);
    }

    /**
     * @test
     */
    public function testFindYarnViaDefault(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('*'));

        $config = $this->factoryConfig([Config::PACKAGE_MANAGER => 'yarn']);
        $commands = $finder->findForConfig($config, 'x/x', __DIR__);
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

        $config = $this->factoryConfig([Config::PACKAGE_MANAGER => 'NPM']);
        $commands = $finder->findForConfig($config, 'x/x', __DIR__);
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

        $config = $this->factoryConfig([
            Config::PACKAGE_MANAGER => [
                'dependencies' => [
                    'install' => 'custom npm install',
                    'update' => 'custom npm update',
                ],
                'script' => 'custom npm script %s',
            ],
        ]);

        $commands = $finder->findForConfig($config, 'x/x', __DIR__);
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertSame('custom npm install', $commands->installCmd($io));
        static::assertSame('custom npm update', $commands->updateCmd($io));
        static::assertStringContainsString('custom npm script run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindCustomInvalid(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor(null));

        $config = $this->factoryConfig([
            Config::PACKAGE_MANAGER => [
                'x' => null,
                'script' => 'custom script %s',
            ],
        ]);

        $this->expectExceptionMessageMatches('/not found/i');
        $finder->findForConfig($config, 'x/x', __DIR__);
    }

    /**
     * @test
     */
    public function testFindCustomInvalidFallbacks(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('npm'));

        $config = $this->factoryConfig([
            Config::PACKAGE_MANAGER => [
                'x' => null,
                'script' => 'custom script %s',
            ],
        ]);

        $commands = $finder->findForConfig($config, 'x/x', __DIR__);
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

        $config = $this->factoryConfig([Config::PACKAGE_MANAGER => 'meh']);
        $commands = $finder->findForConfig($config, 'x/x', __DIR__);
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

        $finder->findForConfig(
            $this->factoryConfig([Config::PACKAGE_MANAGER => 'meh']),
            'x/x',
            __DIR__
        );
    }

    /**
     * @param array $settings
     * @param string|null $dir
     * @return Config
     */
    public function factoryConfig(array $settings = [], ?string $dir = null): Config
    {
        return Config::forComposerPackage(
            (new ArrayLoader())->load([
                'name' => 'inpsyde/test',
                'description' => 'A test',
                'license' => 'MIT',
                'version' => '1.0',
                'extra' => [Config::EXTRA_KEY => $settings],
            ]),
            $dir ?? __DIR__,
            EnvResolver::new('test', true),
            new Filesystem()
        );
    }

    /**
     * @param array $extra
     * @param string|null $dir
     * @return Asset
     */
    public function factoryAsset(string $dir): Asset
    {
        $data = json_decode(file_get_contents("{$dir}/composer.json"), true);
        if (empty($data['version'])) {
            $data['version'] = '1.0';
        }
        $package = (new ArrayLoader())->load($data);

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
