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
use Inpsyde\AssetsCompiler\PreCompilation;
use Inpsyde\AssetsCompiler\Process\Results;
use Inpsyde\AssetsCompiler\Process\ParallelManager;
use Inpsyde\AssetsCompiler\Util\Io;
use Symfony\Component\Process\Process;

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
     * @var Commands
     */
    private $commands;

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
     * @param Io $io
     * @param RootConfig $config
     * @param Commands $commands
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
        Commands $commands,
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
            $commands,
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
     * @param Commands $commands
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
        Commands $commands,
        ProcessExecutor $executor,
        ParallelManager $parallelManager,
        Locker $locker,
        PreCompilation\Handler $preCompiler,
        callable $outputHandler,
        Filesystem $filesystem
    ) {

        $this->io = $io;
        $this->config = $config;
        $this->commands = $commands;
        $this->executor = $executor;
        $this->parallelManager = $parallelManager;
        $this->locker = $locker;
        $this->preCompiler = $preCompiler;
        $this->outputHandler = $outputHandler;
        $this->filesystem = $filesystem;
    }

    /**
     * @param \Iterator $assets
     * @return bool
     */
    public function process(\Iterator $assets): bool
    {
        $toWipe = [];
        $stopOnFailure = $this->config->stopOnFailure();
        $return = true;
        $processManager = $this->parallelManager;

        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                throw new \Exception('Invalid data to process.');
            }

            [$name, $path, $shouldWipe] = $this->assetProcessInfo($asset);
            if (!$name || !$path || ($shouldWipe === null) || $this->maybeSkipAsset($asset)) {
                continue;
            }

            $action = $asset->isUpdate() ? 'updating' : 'installation';
            $this->io->writeVerboseComment("Starting dependencies {$action} for '{$name}'...");
            $installedDeps = $this->doDependencies($asset);

            if (!$installedDeps && $stopOnFailure) {
                return false;
            }

            $return = $installedDeps && $return;

            $commands = $this->buildScriptCommands($asset);

            // No script, we can lock already
            if (!$commands) {
                $this->locker->lock($asset);
                $shouldWipe and $this->wipeNodeModules($path);

                continue;
            }

            $processManager = $processManager->pushAssetToProcess($asset, ...$commands);
            $shouldWipe and $toWipe[$name] = $shouldWipe;
        }

        $results = $processManager->execute($this->io, $stopOnFailure);

        return $this->handleResults($results, $toWipe) && $return;
    }

    /**
     * @param Asset $asset
     * @return array{string, string, bool}|array{null, null, null}
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
     * @return bool
     */
    private function maybeSkipAsset(Asset $asset): bool
    {
        $name = $asset->name();

        if ($this->locker->isLocked($asset)) {
            $this->io->writeVerbose("Not processing '{$name}' because already processed.");

            return true;
        }

        if ($this->preCompiler->tryPrecompiled($asset, $this->config->defaultEnv())) {
            $this->io->writeInfo("Used pre-processed assets for '{$name}'.");
            $this->locker->lock($asset);

            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    private function doDependencies(Asset $asset): bool
    {
        $isUpdate = $asset->isUpdate();
        $isInstall = $asset->isInstall();

        if (!$isUpdate && !$isInstall) {
            return true;
        }

        $command = $isUpdate
            ? $this->commands->updateCmd($this->io)
            : $this->commands->installCmd($this->io);

        $cwd = $asset->path();
        $exitCode = $this->executor->execute($command ?? '', $this->outputHandler, $cwd);

        return $exitCode === 0;
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
     * @return array<string>|null
     */
    private function buildScriptCommands(Asset $asset): ?array
    {
        $scripts = $asset->script();
        if (!$scripts) {
            return null;
        }

        $assetCommands = [];
        $assetEnv = $asset->env();
        foreach ($scripts as $script) {
            $command = $this->commands->scriptCmd($script, $assetEnv);
            $command and $assetCommands[] = $command;
        }

        return $assetCommands;
    }
}
