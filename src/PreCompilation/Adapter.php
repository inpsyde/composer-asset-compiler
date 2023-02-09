<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Inpsyde\AssetsCompiler\Asset\Asset;

interface Adapter
{
    /**
     * @return string
     */
    public function id(): string;

    /**
     * @param Asset $asset
     * @param string $hash
     * @param string $source
     * @param string $targetDir
     * @param array $config
     * @param array $environment
     * @return bool
     */
    public function tryPrecompiled(
        Asset $asset,
        string $hash,
        string $source,
        string $targetDir,
        array $config,
        array $environment
    ): bool;
}
