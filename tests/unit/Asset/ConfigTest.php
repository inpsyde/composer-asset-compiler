<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit\Asset;

use Composer\Package\Package;
use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\PackageManager\PackageManager;
use Inpsyde\AssetsCompiler\PreCompilation;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class ConfigTest extends TestCase
{
    /**
     * @test
     */
    public function testForAssetConfigInRootAndPackageOrDefault(): void
    {
        $config = Config::forAssetConfigInRoot(true, $this->factoryEnvResolver());

        static::assertSame([], $config->defaultEnv());
        static::assertNull($config->dependencies());
        static::assertFalse($config->isByPackage());
        static::assertFalse($config->isDisabled());
        static::assertFalse($config->isForcedDefault());
        static::assertFalse($config->isRunnable());
        static::assertTrue($config->isValid());
        static::assertNull($config->packageManager());
        static::assertNull($config->preCompilationConfig());
        static::assertNull($config->scripts());
        static::assertTrue($config->usePackageLevelOrDefault());
    }

    /**
     * @test
     */
    public function testForAssetConfigInRootDisabled(): void
    {
        $config = Config::forAssetConfigInRoot(false, $this->factoryEnvResolver());

        static::assertSame([], $config->defaultEnv());
        static::assertNull($config->dependencies());
        static::assertFalse($config->isByPackage());
        static::assertTrue($config->isDisabled());
        static::assertFalse($config->isForcedDefault());
        static::assertFalse($config->isRunnable());
        static::assertTrue($config->isValid());
        static::assertNull($config->packageManager());
        static::assertNull($config->preCompilationConfig());
        static::assertNull($config->scripts());
        static::assertFalse($config->usePackageLevelOrDefault());
    }

    /**
     * @test
     */
    public function testForAssetConfigInRootForcedDefaults(): void
    {
        $config = Config::forAssetConfigInRoot('force-defaults', $this->factoryEnvResolver());

        static::assertSame([], $config->defaultEnv());
        static::assertNull($config->dependencies());
        static::assertFalse($config->isByPackage());
        static::assertFalse($config->isDisabled());
        static::assertTrue($config->isForcedDefault());
        static::assertFalse($config->isRunnable());
        static::assertTrue($config->isValid());
        static::assertNull($config->packageManager());
        static::assertNull($config->preCompilationConfig());
        static::assertNull($config->scripts());
        static::assertFalse($config->usePackageLevelOrDefault());
    }

    /**
     * @test
     */
    public function testForAssetConfigInRootWithScript(): void
    {
        $config = Config::forAssetConfigInRoot('build', $this->factoryEnvResolver());

        static::assertSame([], $config->defaultEnv());
        static::assertSame('install', $config->dependencies());
        static::assertFalse($config->isByPackage());
        static::assertFalse($config->isDisabled());
        static::assertFalse($config->isForcedDefault());
        static::assertTrue($config->isRunnable());
        static::assertTrue($config->isValid());
        static::assertNull($config->packageManager());
        static::assertTrue($config->preCompilationConfig() instanceof PreCompilation\Config);
        static::assertFalse($config->preCompilationConfig()->isValid());
        static::assertSame(['build'], $config->scripts());
        static::assertFalse($config->usePackageLevelOrDefault());
    }

    /**
     * @test
     */
    public function testForAssetConfigInRootWithConfig(): void
    {
        $config = Config::forAssetConfigInRoot($this->configSample(), $this->factoryEnvResolver());

        $this->assertConfig($config, false);
    }

    /**
     * @test
     */
    public function testForPackageWithoutCustomConfigFile(): void
    {
        $package = new Package('test', '1.0.0.0', '1.0');
        $package->setExtra([Config::EXTRA_KEY => $this->configSample()]);

        $config = Config::forComposerPackage(
            $package,
            __DIR__,
            $this->factoryEnvResolver(),
            new Filesystem()
        );

        $this->assertConfig($config, true);
    }

    /**
     * @test
     */
    public function testForPackageWithCustomConfigFile(): void
    {
        $package = new Package('test', '1.0.0.0', '1.0');

        $dir = vfsStream::setup('exampleDir');
        $configFile = (new vfsStreamFile('assets-compiler.json'))
            ->withContent(json_encode($this->configSample()));
        $dir->addChild($configFile);

        $config = Config::forComposerPackage(
            $package,
            $dir->url(),
            $this->factoryEnvResolver(),
            new Filesystem()
        );

        $this->assertConfig($config, true);
    }

    /**
     * @return EnvResolver
     */
    private function factoryEnvResolver(): EnvResolver
    {
        return EnvResolver::new('test', false);
    }

    /**
     * @param Config $config
     * @param bool $byPackage
     * @return void
     */
    private function assertConfig(Config $config, bool $byPackage): void
    {
        static::assertSame($byPackage ? ['foo' => 'bar'] : [], $config->defaultEnv());
        static::assertSame('install', $config->dependencies());
        static::assertSame($byPackage, $config->isByPackage());
        static::assertFalse($config->isDisabled());
        static::assertFalse($config->isForcedDefault());
        static::assertTrue($config->isRunnable());
        static::assertTrue($config->isValid());
        static::assertTrue($config->packageManager() instanceof PackageManager);
        static::assertSame('npm', $config->packageManager()->name());
        static::assertTrue($config->preCompilationConfig() instanceof PreCompilation\Config);
        static::assertTrue($config->preCompilationConfig()->isValid());
        static::assertSame(['build-test'], $config->scripts());
        static::assertFalse($config->usePackageLevelOrDefault());
    }

    /**
     * @return array
     */
    private function configSample(): array
    {
        $json = <<<'JSON'
{
    "default-env": {
        "foo": "bar"
    },
    "env": {
        "production": false,
        "$default-no-dev": {
            "package-manager": "npm",
            "script": {
                "env": {
                    "test": "build-test",
                    "$default": "build"
                }
            },
            "pre-compiled": {
                "env": {
                    "$default": {
                        "target": "./assets/",
                        "adapter": "gh-action-artifact",
                        "source": "assets-${ref}",
                        "config": {
                            "repository": "acme/some-theme"
                        }
                    },
                    "local": {
                        "adapter": false
                    }
                }
            }
        }
    }
}
JSON;
        return json_decode($json, true);
    }
}
