<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Inpsyde\AssetsCompiler\Util\Io;

class Locker
{
    public const LOCK_FILE = '.composer_compiled_assets';

    /**
     * @var Io
     */
    private $io;

    /**
     * @var HashBuilder
     */
    private $hashBuilder;

    /**
     * @param Io $io
     * @param HashBuilder $hashBuilder
     */
    public function __construct(Io $io, HashBuilder $hashBuilder)
    {
        $this->io = $io;
        $this->hashBuilder = $hashBuilder;
    }

    /**
     * @param Asset $asset
     * @return bool
     */
    public function isLocked(Asset $asset): bool
    {
        $file = ($asset->path() ?? '') . '/' . self::LOCK_FILE;
        if (!file_exists($file)) {
            return false;
        }

        $content = @file_get_contents($file);
        if (!$content) {
            $this->io->writeVerboseError("  Could not read content of lock file {$file}.");

            @unlink($file);

            return false;
        }

        $hash = $this->hashBuilder->forAsset($asset);

        return $hash && trim($content) === $hash;
    }

    /**
     * @param Asset $asset
     * @return void
     */
    public function lock(Asset $asset): void
    {
        $file = ($asset->path() ?? '') . '/' . self::LOCK_FILE;
        $name = $asset->name();

        if (!@file_put_contents($file, $this->hashBuilder->forAsset($asset))) { // phpcs:ignore
            $this->io->writeVerboseError(" Could not write lock file {$file} for {$name}.");
        }
    }
}
