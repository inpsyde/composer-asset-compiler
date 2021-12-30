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
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Defaults;
use Inpsyde\AssetsCompiler\Asset\Factory;
use Inpsyde\AssetsCompiler\Asset\Finder;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class FinderUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testNoRootSettingsAndAutoDiscover(): void
    {
        $found = $this->findPackages(null);

        static::assertCount(3, $found);
        static::assertArrayHasKey('me/foo', $found);
        static::assertArrayHasKey('me/bar', $found);

        /** @var Asset $foo */
        $foo = $found['me/foo'];
        /** @var Asset $bar */
        $bar = $found['me/bar'];

        static::assertSame(['my-name-is-foo'], $foo->script());
        static::assertSame(['my-name-is-bar --default'], $bar->script());
    }

    /**
     * @test
     */
    public function testNoRootSettingsAndAutoNoDiscover(): void
    {
        $found = $this->findPackages(['auto-discover' => false]);

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
            ]
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
            ]
        );
    }

    /**
     * @test
     */
    public function testForceDefaultsFailsIfNoDefaults(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/me\/foo/');

        $this->findPackages(
            [
                'packages' => [
                    'me/foo' => 'force-defaults',
                ],
                'auto-discover' => false,
                'stop-on-failure' => true,
            ]
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
            ]
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
            ]
        );

        static::assertCount(3, $found);

        /** @var Asset $asset */
        foreach ($found as $name => $asset) {
            static::assertSame($name, $asset->name());
            static::assertFalse($asset->isInstall());
            static::assertTrue($asset->isUpdate());
            static::assertSame(['foo', 'bar'], $asset->script());
        }
    }

    /**
     * @param array $settings
     * @param RootConfig|null $config
     * @return array
     */
    private function findPackages(?array $settings): array
    {
        $envResolver = ModeResolver::new('test', true);
        $filesystem = new Filesystem();
        $rootDir = vfsStream::setup('root');

        $data = [
            'name' => 'inpsyde/my-root-package',
            'version' => '1.0',
            'license' => 'MIT',
            'extra' => [Config::EXTRA_KEY => $settings ?? []],
        ];

        $root = (new ArrayLoader())->load($data, RootPackage::class);

        $config = Config::forComposerPackage($root, $rootDir->url(), $envResolver, $filesystem);

        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');
        $packagesDir = vfsStream::setup('exampleDir');
        $packagesDir->addChild($packagesJson);

        /** @var \Mockery\MockInterface|InstallationManager $manager */
        $manager = \Mockery::mock(InstallationManager::class);
        $manager->allows('getInstallPath')
            ->with(\Mockery::type(PackageInterface::class))
            ->andReturns($packagesDir->url());

        /** @var RootConfig $rootConfig */
        $rootConfig = $config->rootConfig();
        $rootEnv = $config->defaultEnv();

        $factory = Factory::new($envResolver, $filesystem, $manager, $rootDir->url(), $rootEnv);
        $defaults = $rootConfig->defaults();

        $finder = Finder::new(
            $rootConfig->packagesData(),
            $envResolver,
            $defaults ? Defaults::new($defaults) : Defaults::empty(),
            $config,
            $rootConfig->stopOnFailure()
        );

        return $finder->find($this->composerRepo(), $root, $factory, $rootConfig->autoDiscover());
    }

    /**
     * @return RepositoryInterface
     */
    private function composerRepo(): RepositoryInterface
    {
        $loader = new ArrayLoader();

        return new ArrayRepository([
            $loader->load([
                'name' => 'me/foo',
                'version' => '1.0',
                'extra' => [
                    'composer-asset-compiler' => [
                        'script' => 'my-name-is-foo',
                    ],
                ],
            ]),
            $loader->load([
                'name' => 'me/bar',
                'version' => '1.0',
                'extra' => [
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
                ],
            ]),
            $loader->load([
                'name' => 'me/baz',
                'version' => '1.0',
            ]),
            $loader->load([
                'name' => 'last/with-env',
                'version' => '1.0',
                'extra' => [
                    'composer-asset-compiler' => [
                        'script' => 'encore ${ENV_NAME}',
                    ],
                ],
            ]),
        ]);
    }
}
