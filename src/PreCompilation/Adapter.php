<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

interface Adapter
{

    /**
     * @return string
     */
    public function id(): string;

    /**
     * @param string $name
     * @param string $hash
     * @param string $source
     * @param string $targetDir
     * @param array $config
     * @param string|null $version
     * @return bool
     */
    public function tryPrecompiled(
        string $name,
        string $hash,
        string $source,
        string $targetDir,
        array $config,
        ?string $version
    ): bool;
}
