<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\HashBuilder;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Util\Io;

class Handler
{
    /**
     * @var HashBuilder
     */
    private $hashBuilder;

    /**
     * @var array<string, Adapter>
     */
    private $adapters = [];

    /**
     * @var string|null
     */
    private $defaultAdapterId;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var EnvResolver
     */
    private $envResolver;

    /**
     * @param HashBuilder $hashBuilder
     * @param Io $io
     * @param Filesystem $filesystem
     * @param EnvResolver $envResolver
     * @return Handler
     */
    public static function new(
        HashBuilder $hashBuilder,
        Io $io,
        Filesystem $filesystem,
        EnvResolver $envResolver
    ): Handler {

        return new static($hashBuilder, $io, $filesystem, $envResolver);
    }

    /**
     * @param HashBuilder $hashBuilder
     * @param Io $io
     * @param Filesystem $filesystem
     * @param EnvResolver $envResolver
     */
    final private function __construct(
        HashBuilder $hashBuilder,
        Io $io,
        Filesystem $filesystem,
        EnvResolver $envResolver
    ) {

        $this->hashBuilder = $hashBuilder;
        $this->io = $io;
        $this->filesystem = $filesystem;
        $this->envResolver = $envResolver;
    }

    /**
     * @param Adapter $adapter
     * @return Handler
     */
    public function registerAdapter(Adapter $adapter): Handler
    {
        $id = $adapter->id();
        $this->adapters or $this->defaultAdapterId = $id;
        $this->adapters[$id] = $adapter;

        return $this;
    }

    /**
     * @param Asset $asset
     * @param array $defaultEnv
     * @param string|null $hashSeed
     * @return bool
     */
    public function tryPrecompiled(Asset $asset, array $defaultEnv, ?string $hashSeed = null): bool
    {
        $config = $asset->preCompilationConfig();
        $adapter = $this->findAdapter($config);
        if (!$adapter) {
            return false;
        }

        $hash = $this->hashBuilder->forAsset($asset, $hashSeed);
        if (!$hash) {
            return false;
        }

        $environment = array_merge(array_filter($defaultEnv), array_filter($asset->env()));
        $placeholders = Placeholders::new($asset, $this->envResolver->env(), $hash);

        $source = $config->source($placeholders, $environment);
        $path = $asset->path();
        $target = $config->target();

        if (!$source || !$path || !$target) {
            return false;
        }

        $name = $asset->name();

        $adapterId = $adapter->id();
        $this->io->writeVerboseComment(
            "Attempting usage of pre-processed data for '{$name}' using {$adapterId} adapter..."
        );

        $saved = $adapter->tryPrecompiled(
            $asset,
            $hash,
            $source,
            $this->filesystem->normalizePath("{$path}/{$target}"),
            $config->config($placeholders, $environment),
            $environment
        );

        if (!$saved) {
            $this->io->writeVerbose(
                "  Could not use pre-processed assets for '{$name}'",
                '  will now install using default configuration.'
            );

            return false;
        }

        return true;
    }

    /**
     * @param Config $config
     * @return Adapter|null
     */
    private function findAdapter(Config $config): ?Adapter
    {
        if (!$config->isValid()) {
            return null;
        }

        $adapterId = $config->adapter() ?? $this->defaultAdapterId;

        return $adapterId ? ($this->adapters[$adapterId] ?? null) : null;
    }
}
