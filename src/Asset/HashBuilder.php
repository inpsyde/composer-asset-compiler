<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Inpsyde\AssetsCompiler\Util\Env;
use Inpsyde\AssetsCompiler\Util\Io;

final class HashBuilder
{
    /** @var array<string, non-falsy-string|null> */
    private array $hashes = [];

    /**
     * @param PathsFinder $pathsFinder
     * @param Io $io
     * @return HashBuilder
     */
    public static function new(PathsFinder $pathsFinder, Io $io): HashBuilder
    {
        return new static($pathsFinder, $io);
    }

    /**
     * @param PathsFinder $pathsFinder
     * @param Io $io
     */
    private function __construct(
        private PathsFinder $pathsFinder,
        private Io $io
    ) {
    }

    /**
     * @param Asset $asset
     * @return non-falsy-string|null
     */
    public function forAsset(Asset $asset): ?string
    {
        $key = $asset->name();
        if (array_key_exists($key, $this->hashes)) {
            return $this->hashes[$key];
        }

        $files = $this->pathsFinder->findAssetPaths($asset);

        if ($this->io->isVerbose()) {
            foreach ($files as $file) {
                $this->io->write("Will use '{$file}' file to calculate package hash");
            }
        }

        $script = Env::replaceEnvVariables(implode(' ', $asset->script()), $asset->env());
        $hashes = $asset->isInstall() ? "|install|{$script}" : "|update|{$script}";
        $done = [];
        foreach ($files as $file) {
            if (isset($done[$file])) {
                continue;
            }
            $done[$file] = true;
            if (file_exists($file) && is_readable($file)) {
                $hashes .= (string) md5_file($file);
            }
        }

        /** @var non-falsy-string $hash */
        $hash = sha1($hashes);
        $this->hashes[$key] = $hash;

        return $hash;
    }
}
