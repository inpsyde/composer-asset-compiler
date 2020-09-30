<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Package;

use Composer\Installer\InstallationManager;
use Composer\Package\Package as ComposerPackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Defaults;
use Inpsyde\AssetsCompiler\Asset\Factory;
use Inpsyde\AssetsCompiler\Asset\Finder;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class FinderTest extends TestCase
{

    /**
     * @test
     */
    public function testNoRootSettingsAndAutoDiscover(): void
    {
        $found = $this->findPackages(null, 'test', true);

        static::assertCount(3, $found);
        static::assertArrayHasKey('me/foo', $found);
        static::assertArrayHasKey('me/bar', $found);

        /** @var Package $foo */
        $foo = $found['me/foo'];
        /** @var Package $bar */
        $bar = $found['me/bar'];

        static::assertSame(['my-name-is-foo'], $foo->script());
        static::assertSame(['my-name-is-bar --default'], $bar->script());
    }

    /**
     * @test
     */
    public function testNoRootSettingsAndAutoNoDiscover(): void
    {
        $found = $this->findPackages(['auto-discover' => false], 'test', true);

        static::assertSame([], $found);
    }

    /**
     * @test
     */
    public function testRootSettingsWithFallbackButNoPackageSettingsAndNoAutoDiscover(): void
    {
        $found = $this->findPackages(
            [
                'packages' => [
                    'me/baz-*' => true,
                ],
                'auto-discover' => false,
                'stop-on-failure' => false,
            ],
            'test',
            true
        );

        static::assertSame([], $found);
    }

    /**
     * @test
     */
    public function testNoSettingsAndNoDefaultsMakeFailureWhenStopOnFailureIsTrue(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/me\/baz-package/');

        $this->findPackages(
            [
                'packages' => [
                    'me/baz-package' => true,
                ],
                'auto-discover' => false,
                'stop-on-failure' => true,
            ],
            'test',
            true
        );
    }

    /**
     * @test
     */
    public function testForceDefaultsFailsIfNoDefaults(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/me\/baz-package/');

        $this->findPackages(
            [
                'packages' => [
                    'me/baz-package' => 'force-defaults',
                ],
                'auto-discover' => false,
                'stop-on-failure' => true,
            ],
            'test',
            true
        );
    }

    /**
     * @test
     */
    public function testExclude(): void
    {
        $found = $this->findPackages(
            [
                'packages' => [
                    'me/foo' => false,
                ],
            ],
            'test',
            true
        );

        static::assertCount(2, $found);
        static::assertArrayHasKey('me/bar', $found);
        static::assertArrayHasKey('last/with-env', $found);

        $bar = $found['me/bar'];

        static::assertSame(['my-name-is-bar --default'], $bar->script());
    }

    /**
     * @test
     */
    public function testForceDefaults(): void
    {
        $found = $this->findPackages(
            [
                'packages' => [
                    'me/*' => 'force-defaults',
                ],
                'defaults' => [
                    'dependencies' => 'update',
                    'script' => ['foo', 'bar'],
                ],
                'auto-discover' => false,
                'stop-on-failure' => true,
            ],
            'test',
            true
        );

        static::assertCount(3, $found);

        /** @var Package $package */
        foreach ($found as $name => $package) {
            static::assertSame($name, $package->name());
            static::assertFalse($package->isInstall());
            static::assertTrue($package->isUpdate());
            static::assertSame(['foo', 'bar'], $package->script());
        }
    }

    /**
     * @param array $settings
     * @param string $env
     * @param bool $isDev
     * @param RootConfig|null $config
     * @return array
     */
    private function findPackages(?array $settings, string $env, bool $isDev): array
    {
        $root = new RootPackage('company/my-root-package', '1.0', '1.0.0.0');

        if ($settings) {
            $root->setExtra(['composer-asset-compiler' => $settings]);
        }

        $envResolver = EnvResolver::new($env, $isDev);
        $filesystem = new Filesystem();

        $config = RootConfig::new($root, $envResolver, $filesystem, __DIR__);

        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');
        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        /** @var \Mockery\MockInterface|InstallationManager $manager */
        $manager = \Mockery::mock(InstallationManager::class);
        $manager->shouldReceive('getInstallPath')
            ->with(\Mockery::type(PackageInterface::class))
            ->andReturn($dir->url());

        $factory = Factory::new($envResolver, $filesystem, $manager, $dir->url());

        $finder = Finder::new(
            $config->packagesData(),
            $envResolver,
            Defaults::new(Config::forAssetConfigInRoot($config->defaults(), $envResolver)),
            __DIR__,
            $config->stopOnFailure()
        );

        return $finder->find($this->composerRepo(), $root, $factory, $config->autoDiscover());
    }

    /**
     * @return RepositoryInterface
     */
    private function composerRepo(): RepositoryInterface
    {
        $foo = new ComposerPackage('me/foo', '1.0', '1.0.0.0');
        $foo->setExtra(
            [
                'composer-asset-compiler' => [
                    'script' => 'my-name-is-foo',
                ],
            ]
        );

        $bar = new ComposerPackage('me/bar', '1.0', '1.0.0.0');
        $bar->setExtra(
            [
                'composer-asset-compiler' => [
                    'env' => [
                        '$default' => [
                            'script' => 'my-name-is-bar --default',
                        ],
                        '$default-no-dev' => [
                            'script' => 'my-name-is-bar --default-no-dev',
                        ],
                        'production' => [
                            'script' => 'my-name-is-bar --production',
                        ],
                    ],
                ],
            ]
        );

        $baz = new ComposerPackage('me/baz-package', '1.0', '1.0.0.0');

        $last = new ComposerPackage('last/with-env', '1.0', '1.0.0.0');
        $last->setExtra(
            [
                'composer-asset-compiler' => [
                    'script' => 'encore ${ENV_NAME}',
                ],
            ]
        );

        return new ArrayRepository([$foo, $bar, $baz, $last]);
    }
}
