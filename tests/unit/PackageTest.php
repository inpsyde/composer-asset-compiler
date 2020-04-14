<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Inpsyde\AssetsCompiler\Package;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class PackageTest extends TestCase
{
    public function testCreatePackageFromJson()
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

    public function testCreatePackageFromJsonDependenciesOnly()
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

    public function testCreatePackageFromScriptOnly()
    {
        $json = <<<JSON
{
	"script": ["foo", "bar"]
}
JSON;
        $package = $this->factoryPackage($json);

        static::assertTrue($package->isValid());
        static::assertFalse($package->isInstall());
        static::assertFalse($package->isUpdate());
        static::assertSame(["foo", "bar"], $package->script());
    }

    public function testInvalidScriptsAreStrippedOut()
    {
        $json = <<<JSON
{
	"script": ["foo", 103, "bar", true, {}, "baz"]
}
JSON;
        $package = $this->factoryPackage($json);

        static::assertTrue($package->isValid());
        static::assertFalse($package->isInstall());
        static::assertFalse($package->isUpdate());
        static::assertSame(["foo", "bar", "baz"], $package->script());
    }

    public function testDefaultIsValidIfConfigAreValid()
    {
        $json = <<<JSON
{
	"dependencies": "install",
	"script": "setup"
}
JSON;
        $package = Package::defaults(json_decode($json, true));

        static::assertTrue($package->isValid());
        static::assertTrue($package->isDefault());
        static::assertTrue($package->isInstall());
        static::assertFalse($package->isUpdate());
        static::assertSame(["setup"], $package->script());
    }

    public function testJsonSerialization()
    {
        $json = <<<JSON
{
	"dependencies": "install",
	"script": "setup"
}
JSON;
        $package = new Package('test/test-package', json_decode($json, true), __DIR__);

        static::assertJsonStringEqualsJsonString($json, json_encode($package));
    }

    /**
     * @param string $json
     * @param string $name
     * @return Package
     */
    private function factoryPackage(string $json, string $name = 'test/test-package'): Package
    {
        $packagesJson = (new vfsStreamFile('package.json'))->withContent('{}');

        $dir = vfsStream::setup('exampleDir');
        $dir->addChild($packagesJson);

        return new Package($name, json_decode($json, true), $dir->url());
    }
}
