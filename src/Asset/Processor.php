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
use Inpsyde\AssetsCompiler\Commands\Commands;
use Inpsyde\AssetsCompiler\Commands\Finder;
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
     * @var RootConfig
     */
    private $config;

    /**
     * @var Finder
     */
    private $commandsFinder;

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
     * @var Commands|null
     */
    private $defaultCommands;

    /**
     * @param Io $io
     * @param RootConfig $config
     * @param Finder $commandsFinder
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
        RootConfig $config,
        Finder $commandsFinder,
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
            $commandsFinder,
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
     * @param RootConfig $config
     * @param Finder $commandsFinder
     * @param ProcessExecutor $executor
     * @param ParallelManager $parallelManager
     * @param Locker $locker
     * @param PreCompilation\Handler $preCompiler
     * @param callable $outputHandler
     * @param Filesystem $filesystem
     */
    private function __construct(
        Io $io,
        RootConfig $config,
        Finder $commandsFinder,
        ProcessExecutor $executor,
        ParallelManager $parallelManager,
        Locker $locker,
        PreCompilation\Handler $preCompiler,
        callable $outputHandler,
        Filesystem $filesystem
    ) {

        $this->io = $io;
        $this->config = $config;
        $this->commandsFinder = $commandsFinder;
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
     * @throws \Exception
     */
    public function process(\Iterator $assets, ?string $hashSeed = null): bool
    {
        $toWipe = [];
        $stopOnFailure = $this->config->stopOnFailure();
        $return = true;
        $processManager = $this->parallelManager;

        foreach ($assets as $asset) {
            if (!($asset instanceof Asset) && $stopOnFailure) {
                throw new \Exception('Invalid data to process.');
            } elseif (!($asset instanceof Asset)) {
                continue;
            }
            [$name, $path, $shouldWipe] = $this->assetProcessInfo($asset);
            if (!$name || !$path || ($shouldWipe === null)) {
                continue;
            }
            if ($this->maybeSkipAsset($asset, $hashSeed)) {
                continue;
            }

            $commands = $this->findCommandsForAsset($asset);
            if (!$commands->isValid() || !$commands->isExecutable($this->executor, $path)) {
                $this->io->writeError("Could not find a package manager on the system.");

                return false;
            }

            $installedDeps = $this->doDependencies($asset, $commands);

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
     * @return array{string|null, string|null, bool|null}
     */
    private function assetProcessInfo(Asset $asset): array
    {
        $name = $asset->name();
        $path = $asset->path();

        if (!$name || !$path) {
            return [null, null, null];
        }

        $shouldWipe = $this->config->isWipeAllowedFor($path);

        return [$name, $path, $shouldWipe];
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
     * @return Commands
     */
    private function findCommandsForAsset(Asset $asset): Commands
    {
        $path = rtrim($asset->path() ?? '', '/');
        $isRoot = $path && ($path === rtrim($this->config->rootDir(), '/'));

        try {
            return $isRoot
                ? $this->defaultCommands()
                : $this->commandsFinder->findForAsset($asset);
        } catch (\Throwable $throwable) {
            if ($isRoot) {
                throw $throwable;
            }
            $error = sprintf(
                'Could not find a package manager for package %s. Switching to default.',
                $asset->name()
            );
            $this->io->writeError($error);

            return $this->defaultCommands();
        }
    }

    /**
     * @return Commands
     */
    private function defaultCommands(): Commands
    {
        try {
            $this->defaultCommands
            or $this->defaultCommands = $this->commandsFinder->find($this->config);

            return $this->defaultCommands;
        } catch (\Throwable $throwable) {
            return Commands::new([], []);
        }
    }

    /**
     * @param Asset $asset
     * @param Commands $commands
     * @return bool
     */
    private function doDependencies(Asset $asset, Commands $commands): bool
    {
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
            ? $commands->updateCmd($this->io)
            : $commands->installCmd($this->io);

        if (!$command) {
            return false;
        }

        $action = $isUpdate ? 'Updating' : 'Installing';
        $name = $asset->name();
        $cmdName = $commands->name();
        $this->io->writeVerboseComment("{$action} dependencies for '{$name}' using {$cmdName}...");

        $command = $this->handleIsolatedCache($commands, $command, $cwd, $name);
        $exitCode = $this->executor->execute($command, $this->outputHandler, $cwd);

        return $exitCode === 0;
    }

    /**
     * @param Commands $commands
     * @param string $command
     * @param string $cwd
     * @param string $assetName
     * @return string
     */
    private function handleIsolatedCache(
        Commands $commands,
        string $command,
        string $cwd,
        string $assetName
    ): string {

        if (!$this->config->isolatedCache()) {
            return $command;
        }

        $isYarn = $commands->isYarn();
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

        $cmdName = $commands->name();
        $cleanCmd = $commands->cleanCacheCmd();
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
     * @param Commands $commands
     * @return list<string>|null
     */
    private function buildScriptCommands(Asset $asset, Commands $commands): ?array
    {
        $scripts = $asset->script();
        if (!$scripts) {
            return null;
        }

        $assetCommands = [];
        $assetEnv = $asset->env();
        foreach ($scripts as $script) {
            $command = $commands->scriptCmd($script, $assetEnv);
            $command and $assetCommands[] = $command;
        }

        $commandsStr = implode(' && ', $assetCommands);
        $name = $asset->name();
        $this->io->writeVerboseComment("Will compile '{$name}' using '{$commandsStr}'.");

        return $assetCommands;
    }
}
