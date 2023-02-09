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
    public const IGNORE_ALL = '*';

    /**
     * @var Io
     */
    private $io;

    /**
     * @var HashBuilder
     */
    private $hashBuilder;

    /**
     * @var boolean
     */
    private $ignoreAll;

    /**
     * @var list<string>
     */
    private $ignored = [];

    /**
     * @param Io $io
     * @param HashBuilder $hashBuilder
     * @param string $ignoreLock
     */
    public function __construct(Io $io, HashBuilder $hashBuilder, string $ignoreLock = '')
    {
        $this->io = $io;
        $this->hashBuilder = $hashBuilder;
        $this->ignoreAll = ($ignoreLock === self::IGNORE_ALL);
        if (!$this->ignoreAll && $ignoreLock) {
            $names = array_map('trim', explode(',', $ignoreLock));
            $this->ignored = array_values(array_filter($names));
        }
    }

    /**
     * @param Asset $asset
     * @param string|null $hashSeed
     * @return bool
     */
    public function isLocked(Asset $asset, ?string $hashSeed = null): bool
    {
        if ($this->ignoreAll) {
            return false;
        }

        $file = ($asset->path() ?? '') . '/' . self::LOCK_FILE;
        if (!@file_exists($file)) {
            return false;
        }

        $name = $asset->name();
        foreach ($this->ignored as $ignored) {
            if (
                $ignored === $name
                || fnmatch($ignored, $name, FNM_PATHNAME | FNM_PERIOD | FNM_CASEFOLD)
            ) {
                $this->io->writeVerboseComment("  Ignoring lock file for {$name}.");

                return false;
            }
        }

        $content = @file_get_contents($file);
        if (!$content) {
            $this->io->writeVerboseError("  Could not read content of lock file {$file}.");

            @unlink($file);

            return false;
        }

        $hash = $this->hashBuilder->forAsset($asset, $hashSeed);

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

        if (!@file_put_contents($file, (string) $this->hashBuilder->forAsset($asset))) { // phpcs:ignore
            $this->io->writeVerboseError(" Could not write lock file {$file} for {$name}.");
        }
    }
}
