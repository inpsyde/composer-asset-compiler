<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Asset;

use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\PackageManager\PackageManager;
use Inpsyde\AssetsCompiler\PackageManager\Finder;
use Inpsyde\AssetsCompiler\PreCompilation;
use Inpsyde\AssetsCompiler\Process\Results;
use Inpsyde\AssetsCompiler\Process\ParallelManager;
use Inpsyde\AssetsCompiler\Util\Io;

class Processor
{
    /**
     * @var Io
     */
    private $io;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Finder
     */
    private $packageManagerFinder;

    /**
     * @var ProcessExecutor
     */
    private $executor;

    /**
     * @var Locker
     */
    private $locker;

    /**
     * @var ParallelManager
     */
    private $parallelManager;

    /**
     * @var PreCompilation\Handler
     */
    private $preCompiler;

    /**
     * @var callable
     */
    private $outputHandler;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var PackageManager|null
     */
    private $defaultPackageManager;

    /**
     * @param Io $io
     * @param Config $config
     * @param Finder $packageManagerFinder
     * @param ProcessExecutor $executor
     * @param ParallelManager $parallelManager
     * @param Locker $locker
     * @param PreCompilation\Handler $preCompiler
     * @param callable $outputHandler
     * @param Filesystem $filesystem
     * @return Processor
     */
    public static function new(
        Io $io,
        Config $config,
        Finder $packageManagerFinder,
        ProcessExecutor $executor,
        ParallelManager $parallelManager,
        Locker $locker,
        PreCompilation\Handler $preCompiler,
        callable $outputHandler,
        Filesystem $filesystem
    ): Processor {

        return new self(
            $io,
            $config,
            $packageManagerFinder,
            $executor,
            $parallelManager,
            $locker,
            $preCompiler,
            $outputHandler,
            $filesystem
        );
    }

    /**
     * @param Io $io
     * @param Config $config
     * @param Finder $packageManagerFinder
     * @param ProcessExecutor $executor
     * @param ParallelManager $parallelManager
     * @param Locker $locker
     * @param PreCompilation\Handler $preCompiler
     * @param callable $outputHandler
     * @param Filesystem $filesystem
     */
    private function __construct(
        Io $io,
        Config $config,
        Finder $packageManagerFinder,
        ProcessExecutor $executor,
        ParallelManager $parallelManager,
        Locker $locker,
        PreCompilation\Handler $preCompiler,
        callable $outputHandler,
        Filesystem $filesystem
    ) {

        $this->io = $io;
        $this->config = $config;
        $this->packageManagerFinder = $packageManagerFinder;
        $this->executor = $executor;
        $this->parallelManager = $parallelManager;
        $this->locker = $locker;
        $this->preCompiler = $preCompiler;
        $this->outputHandler = $outputHandler;
        $this->filesystem = $filesystem;
    }

    /**
     * @param \Iterator $assets
     * @param string|null $hashSeed
     * @return bool
     */
    public function process(\Iterator $assets, ?string $hashSeed = null): bool
    {
        $rootConfig = $this->config->rootConfig();
        if (!$rootConfig) {
            throw new \Error('Invalid root config.');
        }

        $toWipe = [];
        $stopOnFailure = $rootConfig->stopOnFailure();
        $return = true;
        $processManager = $this->parallelManager;

        foreach ($assets as $asset) {
            if (!($asset instanceof Asset) && $stopOnFailure) {
                throw new \Exception('Invalid data to process.');
            } elseif (!($asset instanceof Asset)) {
                continue;
            }
            [$name, $path, $shouldWipe] = $this->assetProcessInfo($asset, $rootConfig);
            if (!$name || !$path || ($shouldWipe === null)) {
                continue;
            }
            if ($this->maybeSkipAsset($asset, $hashSeed)) {
                continue;
            }

            try {
                $commands = $this->findCommandsForAsset($asset, $rootConfig);
            } catch (\Throwable $throwable) {
                $this->io->writeError("Could not find a package manager on the system.");

                return false;
            }

            $installedDeps = $this->doDependencies($asset, $commands, $rootConfig);

            if (!$installedDeps && $stopOnFailure) {
                return false;
            }

            $return = $installedDeps && $return;
            $commandStrings = $this->buildScriptCommands($asset, $commands);

            // No script, we can lock already
            if (!$commandStrings) {
                $this->locker->lock($asset);
                $shouldWipe and $this->wipeNodeModules($path);

                continue;
            }

            $processManager = $processManager->pushAssetToProcess($asset, ...$commandStrings);
            $shouldWipe and $toWipe[$name] = $shouldWipe;
        }

        $results = $processManager->execute($this->io, $stopOnFailure);

        return $this->handleResults($results, $toWipe) && $return;
    }

    /**
     * @param Asset $asset
     * @param RootConfig $root
     * @return array{string|null, string|null, bool|null}
     */
    private function assetProcessInfo(Asset $asset, RootConfig $root): array
    {
        $name = $asset->name();
        $path = $asset->path();

        if (!$name || !$path) {
            return [null, null, null];
        }

        return [$name, $path, $root->isWipeAllowedFor($path)];
    }

    /**
     * @param Asset $asset
     * @param string|null $hashSeed
     * @return bool
     */
    private function maybeSkipAsset(Asset $asset, ?string $hashSeed = null): bool
    {
        $name = $asset->name();

        if ($this->locker->isLocked($asset, $hashSeed)) {
            $this->io->writeVerbose("Not processing '{$name}' because already processed.");

            return true;
        }

        if ($this->preCompiler->tryPrecompiled($asset, $this->config->defaultEnv(), $hashSeed)) {
            $this->io->writeInfo("Used pre-processed assets for '{$name}'.");
            $this->locker->lock($asset);

            return true;
        }

        return false;
    }

    /**
     * @param Asset $asset
     * @param RootConfig $root
     * @return PackageManager
     * @throws \Throwable
     */
    private function findCommandsForAsset(Asset $asset, RootConfig $root): PackageManager
    {
        $isRoot = $asset->path() === $root->path();

        try {
            return $isRoot
                ? $this->defaultPackageManager($root)
                : $this->packageManagerFinder->findForAsset($asset);
        } catch (\Throwable $throwable) {
            if ($isRoot) {
                throw $throwable;
            }
            $error = sprintf(
                'Could not find a package manager for package %s. Switching to default.',
                $asset->name()
            );
            $this->io->writeError($error);

            return $this->defaultPackageManager($root);
        }
    }

    /**
     * @param RootConfig $root
     * @return PackageManager
     */
    private function defaultPackageManager(RootConfig $root): PackageManager
    {
        if (!$this->defaultPackageManager) {
            $this->defaultPackageManager = $this->packageManagerFinder
                ->findForConfig($this->config, $root->name(), $root->path());
        }

        return $this->defaultPackageManager;
    }

    /**
     * @param Asset $asset
     * @param PackageManager $packageManager
     * @param RootConfig $root
     * @return bool
     */
    private function doDependencies(
        Asset $asset,
        PackageManager $packageManager,
        RootConfig $root
    ): bool {

        $isUpdate = $asset->isUpdate();
        $isInstall = $asset->isInstall();

        if (!$isUpdate && !$isInstall) {
            return true;
        }

        $cwd = $asset->path();
        if (!$cwd || !is_dir($cwd)) {
            return false;
        }

        $command = $isUpdate
            ? $packageManager->updateCmd($this->io)
            : $packageManager->installCmd($this->io);

        if (!$command) {
            return false;
        }

        $action = $isUpdate ? 'Updating' : 'Installing';
        $name = $asset->name();
        $cmdName = $packageManager->name();
        $this->io->writeVerboseComment("{$action} dependencies for '{$name}' using {$cmdName}...");

        $command = $this->handleIsolatedCache($packageManager, $root, $command, $cwd, $name);
        $exitCode = $this->executor->execute($command, $this->outputHandler, $cwd);

        return $exitCode === 0;
    }

    /**
     * @param PackageManager $packageManager
     * @param RootConfig $root
     * @param string $command
     * @param string $cwd
     * @param string $assetName
     * @return string
     */
    private function handleIsolatedCache(
        PackageManager $packageManager,
        RootConfig $root,
        string $command,
        string $cwd,
        string $assetName
    ): string {

        if (!$root->isolatedCache()) {
            return $command;
        }

        $isYarn = $packageManager->isYarn();
        $cacheParam = $isYarn ? 'cache-folder' : 'cache';
        if (strpos($command, " --{$cacheParam}") !== false) {
            return $command;
        }

        static $tempDir;
        if (!isset($tempDir)) {
            $tempDirRaw = $this->filesystem->normalizePath(sys_get_temp_dir());
            $tempDir = (is_dir($tempDirRaw) && is_writable($tempDirRaw))
                ? rtrim($tempDirRaw, '/')
                : false;
        }

        $cmdName = $packageManager->name();
        $cleanCmd = $packageManager->cleanCacheCmd();
        $doClean = $tempDir === false;
        $fullPath = $doClean ? '' : "{$tempDir}/composer-asset-compiler/{$cmdName}/{$assetName}";
        try {
            $fullPath and $this->filesystem->ensureDirectoryExists($fullPath);
        } catch (\Throwable $throwable) {
            $doClean = true;
        }

        if ($doClean && !$cleanCmd) {
            $this->io->writeVerboseError(
                "Cache cleanup command not configured for {$cmdName}.",
                "Isolated cache not applicable for '{$assetName}'."
            );

            return $command;
        }

        if ($doClean) {
            $this->io->writeVerbose(
                "Failed creating asset temporary directory.",
                "Will now execute {$cleanCmd} to ensure isolated cache for '{$assetName}'."
            );

            $this->io->writeVerboseComment("Forcing {$cmdName} cache cleanup...");
            $out = null;
            if ($this->executor->execute($cleanCmd, $out, $cwd) !== 0) {
                $this->io->writeVerboseError(
                    "  {$cmdName} cache cleanup failed!",
                    "  Isolated cache not applicable for '{$assetName}'."
                );
            }

            return $command;
        }

        $this->io->writeVerbose("Will use isolated cache path '{$fullPath}' for '{$assetName}'.");

        /** @var string $tempDir */

        return "{$command} --{$cacheParam} {$fullPath}";
    }

    /**
     * @param string $baseDir
     * @return bool|null
     */
    private function wipeNodeModules(string $baseDir): ?bool
    {
        $dir = rtrim($this->filesystem->normalizePath($baseDir), '/') . "/node_modules";
        if (!is_dir($dir)) {
            $this->io->writeVerbose("  '{$dir}' not found, nothing to wipe.");

            return null;
        }

        $this->io->writeVerboseComment("Wiping '{$dir}'...");

        $doneWipe = $this->filesystem->removeDirectory($dir);
        $doneWipe
            ? $this->io->writeVerboseInfo('  success!')
            : $this->io->writeVerboseError('  failed!');

        return $doneWipe;
    }

    /**
     * @param Results $results
     * @param array<string, bool> $toWipe
     * @return bool
     */
    private function handleResults(Results $results, array $toWipe): bool
    {
        if ($results->isEmpty()) {
            $this->io->write('Nothing else to process.');

            return true;
        }

        if ($results->timedOut()) {
            $this->io->writeError(
                'Could not complete processing of assets because timeout of reached.'
            );
        }

        $notExecuted = $results->notExecutedCount();
        if ($notExecuted > 0) {
            $total = $results->total();
            $this->io->writeError(
                "Processing for {$notExecuted} assets out of {$total} did NOT completed."
            );
        }

        $successes = $results->successes();
        while ($successes && !$successes->isEmpty()) {
            $success = $successes->dequeue();
            [, $asset] = $success;
            $this->locker->lock($asset);
            if (!empty($toWipe[$asset->name()])) {
                $path = $asset->path();
                $path and $this->wipeNodeModules($path);
            }
        }

        return $results->isSuccessful();
    }

    /**
     * @param Asset $asset
     * @param PackageManager $packageManager
     * @return list<string>|null
     */
    private function buildScriptCommands(Asset $asset, PackageManager $packageManager): ?array
    {
        $scripts = $asset->script();
        if (!$scripts) {
            return null;
        }

        $assetCommands = [];
        $assetEnv = $asset->env();
        foreach ($scripts as $script) {
            $command = $packageManager->scriptCmd($script, $assetEnv);
            $command and $assetCommands[] = $command;
        }

        $commandsStr = implode(' && ', $assetCommands);
        $name = $asset->name();
        $this->io->writeVerboseComment("Will compile '{$name}' using '{$commandsStr}'.");

        return $assetCommands;
    }
}
