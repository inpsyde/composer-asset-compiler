<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Commands;

use ArrayIterator;
use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Commands\Finder;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Symfony\Component\Console\Output\OutputInterface;

class FinderTest extends TestCase
{

    /**
     * @test
     */
    public function testFindYarnViaDiscover(): void
    {
        $finder = $this->factoryFinder($this->factoryExecutor('yarn'), []);

        $commands = $finder->find(__DIR__);
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
        $finder = $this->factoryFinder($this->factoryExecutor('npm'), []);

        $commands = $finder->find(__DIR__);
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
        $finder = $this->factoryFinder($this->factoryExecutor(null), []);

        $this->expectExceptionMessageMatches('/not found/i');

        $finder->find(__DIR__);
    }

    /**
     * @test
     */
    public function testFindYarnViaDefault(): void
    {
        $finder = $this->factoryFinder(
            $this->factoryExecutor(null),
            [RootConfig::COMMANDS => 'yarn']
        );

        $commands = $finder->find(__DIR__);
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
        $finder = $this->factoryFinder(
            $this->factoryExecutor(null),
            [RootConfig::COMMANDS => 'NPM']
        );

        $commands = $finder->find(__DIR__);
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
        $finder = $this->factoryFinder(
            $this->factoryExecutor(null),
            [
                RootConfig::COMMANDS => [
                    'dependencies' => [
                        'install' => 'custom install',
                        'update' => 'custom update',
                    ],
                    'script' => 'custom script %s',
                ],
            ]
        );

        $commands = $finder->find(__DIR__);
        $io = $this->factoryIo();

        static::assertTrue($commands->isValid());
        static::assertSame('custom install', $commands->installCmd($io));
        static::assertSame('custom update', $commands->updateCmd($io));
        static::assertStringContainsString('custom script run', $commands->scriptCmd('run'));
    }

    /**
     * @test
     */
    public function testFindCustomInvalid(): void
    {
        $finder = $this->factoryFinder(
            $this->factoryExecutor(null),
            [RootConfig::COMMANDS => ['x' => null, 'script' => 'custom script %s']]
        );

        $this->expectExceptionMessageMatches('/not found/i');
        $finder->find(__DIR__);
    }

    /**
     * @test
     */
    public function testInvalidSettingsSuccessfullyFallbacksToDiscover(): void
    {
        $finder = $this->factoryFinder(
            $this->factoryExecutor('npm'),
            [RootConfig::COMMANDS => 'meh']
        );

        $commands = $finder->find(__DIR__);
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
        $finder = $this->factoryFinder(
            $this->factoryExecutor(null),
            [RootConfig::COMMANDS => 'meh']
        );

        $this->expectExceptionMessageMatches('/not found/i');

        $finder->find(__DIR__);
    }

    /**
     * @param ProcessExecutor|null $executor
     * @param array $extra
     * @return Finder
     */
    private function factoryFinder(?ProcessExecutor $executor = null, array $extra = []): Finder
    {
        $package = new RootPackage('company/name', '1.0.0.0', '1');
        $package->setExtra([Config::EXTRA_KEY => $extra]);

        $config = RootConfig::new(
            $package,
            EnvResolver::new('test', true),
            new Filesystem()
        );

        return Finder::new(
            $config,
            $executor ?? new ProcessExecutor(),
            $this->factoryIo(OutputInterface::VERBOSITY_VERBOSE)
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
                    if (!$which) {
                        return 1;
                    }

                    return strpos($discover, $which) === false ? 1 : 0;
                }
            );

        return $executor;
    }
}
