<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\PreCompilation;

use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\PreCompilation\Placeholders;
use Inpsyde\AssetsCompiler\Tests\UnitTestCase;
use Inpsyde\AssetsCompiler\Util\ModeResolver;

class PlaceholdersUnitTest extends UnitTestCase
{
    /**
     * @test
     */
    public function testReplacements(): void
    {
        $config = Config::forAssetConfigInRoot(true, ModeResolver::new('test', true));
        $asset = Asset::new('inpsyde/test', $config, __DIR__, '1.0.0', 'aaa0bbb');
        $placeholders = Placeholders::new($asset, 'test', 'a0a0a0a');

        $original = '${a-${ref}/${version}/${mode}-${hash}/${ref}.${EXT}}';
        $replaced = $placeholders->replace($original, ['EXT' => 'php']);

        $expected = '${a-aaa0bbb/1.0.0/test-a0a0a0a/aaa0bbb.php}';

        static::assertSame($expected, $replaced);
    }

    /**
     * @test
     */
    public function testReplacementsEmpty(): void
    {
        $config = Config::forAssetConfigInRoot(true, ModeResolver::new('test', true));
        $asset = Asset::new('inpsyde/test', $config, __DIR__);
        $placeholders = Placeholders::new($asset, 'test', null);

        $original = '${a-${ref}/${version}/${mode}-${hash}/${ref}.${EXT}}';
        $replaced = $placeholders->replace($original, []);

        $expected = '${a-//test-/.}';

        static::assertSame($expected, $replaced);
    }

    /**
     * @test
     */
    public function testStableVersion(): void
    {
        $config = Config::forAssetConfigInRoot(true, ModeResolver::new('test', true));
        $assetStable = Asset::new('inpsyde/test', $config, __DIR__, '1.0');
        $assetDev = Asset::new('inpsyde/test', $config, __DIR__, 'dev-master');
        $placeholdersStable = Placeholders::new($assetStable, 'test', null);
        $placeholdersDev = Placeholders::new($assetDev, 'test', null);

        static::assertTrue($placeholdersStable->hasStableVersion());
        static::assertFalse($placeholdersDev->hasStableVersion());
    }
}
