<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Inpsyde\AssetsCompiler\PreCompilation;
use Inpsyde\AssetsCompiler\PreCompilation\Handler;
use Inpsyde\AssetsCompiler\Util\Env;
use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Util\ModeResolver;

class Info
{
    /** @var array<string, non-empty-array<string,mixed>> */
    private array $cache = [];

    /**
     * @param \Iterator<Asset> $assets
     * @param HashBuilder $hashBuilder
     * @param Handler $preCompilation
     * @param PathsFinder $pathsFinder
     * @param ModeResolver $modeResolver
     * @param RootConfig $rootConfig,
     * @param Io $io
     * @return static
     */
    public static function new(
        \Iterator $assets,
        HashBuilder $hashBuilder,
        PreCompilation\Handler $preCompilation,
        PathsFinder $pathsFinder,
        ModeResolver $modeResolver,
        RootConfig $rootConfig,
        Io $io
    ): static {

        return new static(
            $assets,
            $hashBuilder,
            $preCompilation,
            $pathsFinder,
            $modeResolver,
            $rootConfig,
            $io
        );
    }

    /**
     * @param \Iterator<Asset> $assets
     * @param HashBuilder $hashBuilder
     * @param Handler $preCompilation
     * @param PathsFinder $pathsFinder
     * @param ModeResolver $modeResolver
     * @param RootConfig $rootConfig
     * @param Io $io
     */
    final private function __construct(
        private \Iterator $assets,
        private HashBuilder $hashBuilder,
        private PreCompilation\Handler $preCompilation,
        private PathsFinder $pathsFinder,
        private ModeResolver $modeResolver,
        private RootConfig $rootConfig,
        private Io $io
    ) {
    }

    /**
     * @param Asset|string $asset
     * @param Asset|string ...$assets
     * @return ($assets is non-empty-array
     *  ? non-empty-list<non-empty-array<string, mixed>>
     *  : non-empty-array<string, mixed>)
     */
    public function assetInfo(Asset|string $asset, Asset|string ...$assets): array
    {
        $isSingle = $assets === [];
        array_unshift($assets, $asset);

        $names = [];
        $successes = [];
        $errors = [];
        foreach ($assets as $oneAsset) {
            $name = is_string($oneAsset) ? $oneAsset : $oneAsset->name();
            $names[] = $name;

            try {
                $info = $this->oneAssetInfo($oneAsset);
                $successes[] = $info;
            } catch (\Throwable) {
                $errors[] = $name;
                continue;
            }
        }
        /** @psalm-suppress RedundantCondition */
        $this->maybeFail($successes, $errors, $names);
        /** @var non-empty-list<non-empty-array<string, mixed>> $successes */
        return $isSingle ? $successes[0] : $successes;
    }

    /**
     * @param Asset $asset
     * @return non-empty-list<array<string, mixed>>
     */
    public function allAssetsInfo(): array
    {
        $successes = [];
        $errors = [];
        $names = [];
        $root = null;
        /** @var Asset $asset */
        foreach ($this->assets as $asset) {
            $name = $asset->name();
            $names[] = $name;
            try {
                $info = $this->oneAssetInfo($asset);
                $successes[] = $info;
            } catch (\Throwable) {
                $errors[] = $name;
                continue;
            }
            if ($asset->isRoot()) {
                $root = $info;
                continue;
            }

            $successes[] = $info;
        }

        $root or $root = $this->oneAssetInfo($this->rootAsset());
        array_unshift($successes, $root);

        /** @psalm-suppress RedundantCondition */
        $this->maybeFail($successes, $errors, $names);
        /** @var non-empty-list<non-empty-array<string, mixed>> $successes */
        return $successes;
    }

    /**
     * @param Asset $asset
     * @return list<non-empty-string>
     */
    public function allAssetsPaths(): array
    {
        return $this->pathsFinder->findAllAssetsPaths();
    }

    /**
     * @param Asset $asset
     * @return non-empty-array<string, mixed>
     */
    public function rootAssetInfo(): array
    {
        $info = [];
        $found = false;
        foreach ($this->assets as $asset) {
            if ($asset->isRoot()) {
                $found = true;
                $info = $this->oneAssetInfo($asset);
                break;
            }
        }

        $found or $info = $this->oneAssetInfo($this->rootAsset());

        /** @psalm-suppress RedundantCondition */
        $this->maybeFail($info, [], 'root');

        return $info;
    }

    /**
     * @param Asset|string $asset
     * @return non-empty-array<string, mixed>
     */
    private function oneAssetInfo(Asset|string $asset): array
    {
        if (is_string($asset)) {
            $assetName = $asset;
            $asset = $this->findByName($asset);
            if ($asset === null) {
                throw new \Error("No asset found for '{$assetName}'.");
            }
        }

        $key = $asset->name();
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $hash = $this->hashBuilder->forAsset($asset);

        $scripts = [];
        foreach ($asset->script() as $script) {
            $scripts[] = Env::replaceEnvVariables($script, $asset->env());
        }

        $this->cache[$key] = [
            'name' => $key,
            'is_root' => $asset->isRoot(),
            'to_be_compiled' => $asset->isValid(),
            'dependencies' => match (true) {
                $asset->isUpdate() => Config::UPDATE,
                $asset->isInstall() => Config::INSTALL,
                default => Config::NONE,
            },
            'script' => $scripts,
            'hash' => $hash,
            'install_path' => $asset->path(),
            'watchable_paths' => $this->pathsFinder->findAssetPaths($asset),
            'precompilation' => $this->preCompilationConfig($asset, $hash ?? ''),
            'version' => $asset->version(),
            'isolated_cache' => $asset->isolatedCache(),
            'reference' => $asset->reference(),
            'environment' => $asset->env(),
        ];

        return $this->cache[$key];
    }

    /**
     * @param Asset $asset
     * @param string $hash
     * @return array<string, mixed>
     */
    private function preCompilationConfig(Asset $asset, string $hash): array
    {
        $preCompilationConfig = $asset->preCompilationConfig();
        $mode = $this->modeResolver->mode();
        $placeholders = PreCompilation\Placeholders::new($asset, $mode, $hash);
        $adapter = $this->preCompilation->findAdapter($preCompilationConfig, $placeholders);
        if (!$adapter) {
            return [
                'is_valid' => false,
                'adapter' => null,
                'source' => null,
                'target' => null,
                'params' => null,
            ];
        }

        $environment = $asset->env();

        return [
            'is_valid' => $preCompilationConfig->isValid(),
            'adapter' => $adapter->id(),
            'source' => $preCompilationConfig->source($placeholders, $environment),
            'target' => $preCompilationConfig->target($placeholders),
            'params' => $preCompilationConfig->config($placeholders, $environment),
        ];
    }

    /**
     * @param string $name
     * @return Asset|null
     */
    private function findByName(string $name): ?Asset
    {
        foreach ($this->assets as $asset) {
            if ($name === $asset->name()) {
                return $asset;
            }
        }

        if ($name === $this->rootConfig->name()) {
            return $this->rootAsset();
        }

        return null;
    }

    /**
     * @param array $successes
     * @param list<string> $errors
     * @param list<string>|string $names
     * @return void
     *
     * @psalm-assert non-empty-array $successes
     */
    private function maybeFail(array $successes, array $errors, array|string $names = []): void
    {
        if (($successes !== []) && (($errors === []))) {
            return;
        }

        $allFailed = $successes === [];
        $errorMessage = "Failed obtaining assets compilation info for %s.";
        $reason = match (true) {
            ($names === 'root') => 'root asset',
            $allFailed => 'any asset',
            ($errors === []) => 'given assets',
            default => sprintf(': "%s"', implode('", "', $errors)),
        };

        if ($allFailed) {
            throw new \Error(sprintf($errorMessage, $reason));
        }

        $this->io->writeError(sprintf($errorMessage, $reason));
    }

    /**
     * @return Asset
     */
    private function rootAsset(): Asset
    {
        return Asset::new(
            $this->rootConfig->name(),
            $this->rootConfig->config(),
            $this->rootConfig->path(),
            isRoot: true
        );
    }
}
