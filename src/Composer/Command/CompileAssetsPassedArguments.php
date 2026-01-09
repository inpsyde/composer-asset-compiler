<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

class CompileAssetsPassedArguments
{
    /** @var bool  */
    private $isDev;
    /** @var null|string  */
    private $mode;
    /** @var string  */
    private $ignoreLock;
    /** @var null|true */
    private $clearPackageManagerCache = null;
    /** @var null|true */
    private $forceDeleteNodeModules = null;
    /** @var int|null  */
    private $maxParallelProcesses = null;

    public function __construct(
        bool $isDev,
        string $ignoreLock,
        ?string $mode = null,
        ?bool $clearPackageManagerCache = null,
        ?bool $forceDeleteNodeModules = null,
        ?int $maxParallelProcesses = null
    ) {

        $this->isDev = $isDev;
        $this->mode = $mode;
        $this->ignoreLock = $ignoreLock;
        $this->clearPackageManagerCache = $clearPackageManagerCache;
        $this->forceDeleteNodeModules = $forceDeleteNodeModules;
        $this->maxParallelProcesses = $maxParallelProcesses;
    }

    public function isDev(): bool
    {
        return $this->isDev;
    }

    public function mode(): ?string
    {
        return $this->mode;
    }

    public function ignoreLock(): string
    {
        return $this->ignoreLock;
    }

    public function clearPackageManagerCache(): ?bool
    {
        return $this->clearPackageManagerCache;
    }

    public function forceDeleteNodeModules(): ?bool
    {
        return $this->forceDeleteNodeModules;
    }

    public function maxParallelProcesses(): ?int
    {
        return $this->maxParallelProcesses;
    }
}
