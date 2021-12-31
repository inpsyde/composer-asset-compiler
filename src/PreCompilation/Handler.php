<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PreCompilation;

use Composer\Util\Filesystem;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\HashBuilder;
use Inpsyde\AssetsCompiler\Util\ModeResolver;
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
     * @var ModeResolver
     */
    private $modeResolver;

    /**
     * @param HashBuilder $hashBuilder
     * @param Io $io
     * @param Filesystem $filesystem
     * @param ModeResolver $modeResolver
     * @return Handler
     */
    public static function new(
        HashBuilder $hashBuilder,
        Io $io,
        Filesystem $filesystem,
        ModeResolver $modeResolver
    ): Handler {

        return new static($hashBuilder, $io, $filesystem, $modeResolver);
    }

    /**
     * @param HashBuilder $hashBuilder
     * @param Io $io
     * @param Filesystem $filesystem
     * @param ModeResolver $modeResolver
     */
    final private function __construct(
        HashBuilder $hashBuilder,
        Io $io,
        Filesystem $filesystem,
        ModeResolver $modeResolver
    ) {

        $this->hashBuilder = $hashBuilder;
        $this->io = $io;
        $this->filesystem = $filesystem;
        $this->modeResolver = $modeResolver;
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
     * @return bool
     */
    public function tryPrecompiled(Asset $asset): bool
    {
        $hash = $this->hashBuilder->forAsset($asset) ?? '';
        $placeholders = Placeholders::new($asset, $this->modeResolver->mode(), $hash);

        $config = $asset->preCompilationConfig();
        $adapter = $this->findAdapter($config, $placeholders);
        if (!$adapter) {
            return false;
        }

        $environment = $asset->env();
        $source = $config->source($placeholders, $environment);
        $path = $asset->path();
        $target = $config->target($placeholders);

        if (!$source || !$path || !$target) {
            $this->io->writeVerboseComment("Found no pre-processed configuration for '{$name}'.");
            return false;
        }

        $name = $asset->name();

        $adapterId = $adapter->id();
        $this->io->writeComment(
            "Trying to use of pre-processed data for '{$name}' via {$adapterId} adapter..."
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
            $this->io->write(
                "  Could not use pre-processed assets for '{$name}'",
                '  will now install using default configuration.'
            );

            return false;
        }

        return true;
    }

    /**
     * @param Config $config
     * @param Placeholders $placeholders
     * @return Adapter|null
     */
    public function findAdapter(Config $config, Placeholders $placeholders): ?Adapter
    {
        if (!$config->isValid()) {
            return null;
        }

        $adapterId = $config->adapter($placeholders) ?? $this->defaultAdapterId;

        return $adapterId ? ($this->adapters[$adapterId] ?? null) : null;
    }
}
