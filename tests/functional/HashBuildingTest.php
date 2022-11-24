<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests;

use Composer\IO\IOInterface;
use Inpsyde\AssetsCompiler\Asset\Asset;

/**
 * @runTestsInSeparateProcesses
 */
class HashBuildingTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function testHashIsBuiltUsingPatterns(): void
    {
        $this->composerUpdate(getenv('RESOURCES_DIR') . '/05');

        $factory = $this->factoryFactory(IOInterface::VERBOSE);

        /** @var Asset $asset */
        $asset = $factory->assets()->current();
        $hash = $factory->hashBuilder()->forAsset($asset);

        static::assertSame(40, strlen($hash));
        static::assertTrue($this->io->hasOutputThatMatches('~two/a\.css~'));
        static::assertTrue($this->io->hasOutputThatMatches('~two/three/b\.js~'));
        static::assertTrue($this->io->hasOutputThatMatches('~two/three/b1\.jsx~'));
        static::assertTrue($this->io->hasOutputThatMatches('~some-file\.js~'));
        static::assertSame([], $this->io->errors);
    }
}
