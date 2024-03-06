<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Asset;

use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class PackageUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testCreatePackageFromJson(): void
    {
        $json = <<<JSON
        {
            "dependencies": "install",
            "script": "setup"
        }
        JSON;
        $package = $this->factoryPackage($json);

        static::assertTrue($package->isValid());
        static::assertTrue($package->isInstall());
        static::assertFalse($package->isUpdate());
        static::assertSame(["setup"], $package->script());
        static::assertSame('test/test-package', $package->name());
    }

    /**
     * @test
     */
    public function testCreatePackageFromJsonDependenciesOnly(): void
    {
        $json = <<<JSON
        {
            "dependencies": "update"
        }
        JSON;
        $package = $this->factoryPackage($json);

        static::assertTrue($package->isValid());
        static::assertFalse($package->isInstall());
        static::assertTrue($package->isUpdate());
        static::assertSame([], $package->script());
    }

    /**
     * @test
     */
    public function testCreatePackageFromScriptOnly(): void
    {
        $json = <<<JSON
        {
            "script": ["foo", "bar"]
        }
        JSON;
        $package = $this->factoryPackage($json);

        static::assertTrue($package->isValid());
        static::assertTrue($package->isInstall());
        static::assertFalse($package->isUpdate());
        static::assertSame(["foo", "bar"], $package->script());
    }

    /**
     * @test
     */
    public function testInvalidScriptsAreStrippedOut(): void
    {
        $json = <<<JSON
        {
            "script": ["foo", 103, "bar", true, {}, "baz"]
        }
        JSON;
        $package = $this->factoryPackage($json);

        static::assertTrue($package->isValid());
        static::assertTrue($package->isInstall());
        static::assertFalse($package->isUpdate());
        static::assertSame(["foo", "bar", "baz"], $package->script());
    }

    /**
     * @param string $json
     * @param string $name
     * @return Asset
     */
    private function factoryPackage(string $json, string $name = 'test/test-package'): Asset
    {
        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        return Asset::new($name, $this->factoryConfig($json), $dir->url());
    }

    /**
     * @param string $json
     * @return Config
     */
    private function factoryConfig(string $json): Config
    {
        $resolver = ModeResolver::new('', false);

        return Config::forAssetConfigInRoot(json_decode($json, true), $resolver);
    }
}
