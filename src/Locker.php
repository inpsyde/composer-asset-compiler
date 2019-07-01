<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

class Locker
{
    private const LOCK_FILE = '.composer_compiled_assets';

    /**
     * @var Io
     */
    private $io;

    /**
     * @var string
     */
    private $env;

    /**
     * @param Io $io
     * @param string $env
     */
    public function __construct(Io $io, string $env)
    {
        $this->io = $io;
        $this->env = $env;
    }

    /**
     * @param Package $package
     * @return bool
     */
    public function isLocked(Package $package): bool
    {
        $file = ($package->path() ?? '') . '/' . self::LOCK_FILE;
        if (!file_exists($file)) {
            return false;
        }

        $content = @file_get_contents($file);
        if (!$content) {
            $this->io->writeVerboseError("Could not read content of lock file {$file}.");

            @unlink($file);

            return false;
        }

        $hash = $this->hashForPackage($package);

        return $hash && trim($content) === $hash;
    }

    /**
     * @param Package $package
     * @return void
     */
    public function lock(Package $package): void
    {
        $file = ($package->path() ?? '') . '/' . self::LOCK_FILE;

        if (!@file_put_contents($file, $this->hashForPackage($package))) {
            $this->io->writeVerboseError("Could not write lock file {$file}.");
        }
    }

    /**
     * The hash depends on:
     *
     *  - content of package `package.json`
     *  - package settings
     *  - current environment
     *
     * @param Package $package
     * @return string
     */
    private function hashForPackage(Package $package): string
    {
        $file = ($package->path() ?? '') . '/package.json';
        $content = @file_get_contents($file);

        if (!$content) {
            $this->io->writeVerboseError("Could not read content of {$file}.");

            return '';
        }

        return md5($content . $this->env . serialize($package->toArray()));
    }
}
