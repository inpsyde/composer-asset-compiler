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
use Inpsyde\AssetsCompiler\Composer\Command\CompileAssetsPassedArguments;
use Inpsyde\AssetsCompiler\PackageManager\PackageManager;
use Inpsyde\AssetsCompiler\PackageManager\Finder;
use Inpsyde\AssetsCompiler\PreCompilation;
use Inpsyde\AssetsCompiler\PreCompilation\Handler;
use Inpsyde\AssetsCompiler\Process\ParallelProcessManager;
use Inpsyde\AssetsCompiler\Process\ProcessGroup;
use Inpsyde\AssetsCompiler\Process\Results;
use Inpsyde\AssetsCompiler\Process\ParallelManager;
use Inpsyde\AssetsCompiler\Util\Io;
use Symfony\Component\Process\Process;

/*
 * phpcs:disable Inpsyde.CodeQuality.PropertyPerClassLimit
 */
class Processor
{
    const MODE_DEPLOYMENT = 'deployment';
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
     * @var ParallelProcessManager
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
     * @var array{bool, string|null}
     */
    private $tempDir = [false, null];

    /**
     * @var CompileAssetsPassedArguments
     */
    private $passedArguments;

    /**
     * @param Io $io
     * @param Config $config
     * @param Finder $packageManagerFinder
     * @param ProcessExecutor $executor
     * @param ParallelProcessManager $parallelManager
     * @param Locker $locker
     * @param Handler $preCompiler
     * @param callable $outputHandler
     * @param Filesystem $filesystem
     * @param CompileAssetsPassedArguments $arguments
     * @return Processor
     */
    public static function new(
        Io $io,
        Config $config,
        Finder $packageManagerFinder,
        ProcessExecutor $executor,
        ParallelProcessManager $parallelManager,
        Locker $locker,
        PreCompilation\Handler $preCompiler,
        callable $outputHandler,
        Filesystem $filesystem,
        CompileAssetsPassedArguments $arguments
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
            $filesystem,
            $arguments
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
        ParallelProcessManager $parallelManager,
        Locker $locker,
        PreCompilation\Handler $preCompiler,
        callable $outputHandler,
        Filesystem $filesystem,
        CompileAssetsPassedArguments $arguments
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
        $this->passedArguments = $arguments;
    }

    /**
     * @param \Iterator $assets
     * @return bool
     */
    // phpcs:ignore Inpsyde.CodeQuality.FunctionLength.TooLong, Generic.Metrics.CyclomaticComplexity.TooHigh
    public function process(\Iterator $assets): bool
    {
        $rootConfig = $this->config->rootConfig();
        if (!$rootConfig) {
            throw new \Error('Invalid root config.');
        }

        $globalWipeNodeModules = $this->isWipeNodeModulesEnabledGlobally();
        $globalCleanPackageManagerCache = $this->isCleanPackageManagerCacheEnabledGlobally();

        $stopOnFailure = $rootConfig->stopOnFailure();
        $return = true;
        $processManager = $this->parallelManager;

        foreach ($assets as $asset) {
            if (!($asset instanceof Asset) && $stopOnFailure) {
                throw new \Exception('Invalid data to process.');
            }

            [$name, $path, $shouldWipe] = ($asset instanceof Asset)
                ? $this->assetProcessInfo($asset, $rootConfig)
                : [null, null, null];
            if (!$name || !$path || ($shouldWipe === null)) {
                continue;
            }

            if ($globalWipeNodeModules) {
                $shouldWipe = true;
            }

            /** @var Asset $asset */
            if ($this->maybeSkipAsset($asset)) {
                continue;
            }

            try {
                $commands = $this->findCommandsForAsset($asset, $rootConfig);
            } catch (\Throwable $throwable) {
                $this->io->writeError("Could not find a package manager on the system.");

                return false;
            }

            $assetCommands = [];

            $installCommand = $this->buildDependenciesCommand($asset, $commands);

            if (is_string($installCommand) && $installCommand) {
                $assetCommands[] = [
                    'path' => $asset->path(),
                    'command' => $installCommand,
                ];
            }

            $commandStrings = $this->buildScriptCommands($asset, $commands);

            foreach ($commandStrings as $commandString) {
                $assetCommands[] = [
                    'path' => $asset->path(),
                    'command' => $commandString,
                ];
            }

            if ($shouldWipe) {
                $deleteNodeModulesCommand = $this->wipeNodeModulesCommand($path);
                $assetCommands[] = [
                    'path' => $rootConfig->path(),
                    'command' => $deleteNodeModulesCommand,
                ];
            }

            $parentProcessArgs = array_shift($assetCommands);
            $childrenProcessesArgs = $assetCommands;

            $parentProcess = $processManager->createProcess(
                $parentProcessArgs['command'],
                $parentProcessArgs['path']
            );

            $onGroupCompleted = function () use ($asset) {
                $this->io->writeComment(sprintf('Locking asset %s', $asset->name()));
                $this->locker->lock($asset);
            };

            $onParentErrored = static function (Process $parent) {
                throw new \RuntimeException(
                    "Parent process failed: " . $parent->getErrorOutput()
                );
            };

            $onChildErrored = static function (Process $parent) {
                throw new \RuntimeException(
                    "Child process failed: " . $parent->getErrorOutput()
                );
            };

            $onParentProcessStart = function (Process $process) {
                $this->io->writeComment(
                    sprintf(
                        'Starting %s in %s',
                        $process->getCommandLine(),
                        $process->getWorkingDirectory()
                    )
                );
            };

            $onChildProcessStart = function (Process $process) {
                $this->io->writeComment(
                    sprintf(
                        'Starting %s in %s',
                        $process->getCommandLine(),
                        $process->getWorkingDirectory()
                    )
                );
            };

            $processGroup = new ProcessGroup(
                $parentProcess,
                $onParentProcessStart,
                $onChildProcessStart,
                $onGroupCompleted,
                $onParentErrored,
                $onChildErrored
            );
            foreach ($childrenProcessesArgs as $childrenProcessArgs) {
                $childProcess = $processManager->createProcess(
                    $childrenProcessArgs['command'],
                    $childrenProcessArgs['path']
                );
                $processGroup->addChild($childProcess);
            }

            $processManager->addGroup($processGroup);
        }

        /**
         * @param ProcessGroup[] $groups
         * @param int $batchNumber
         * @param int $totalBatches
         * @param int $groupsCount
         * @return void
         */
        $onBatchStartCallback = function (
            array $groups,
            int $batchNumber,
            int $totalBatches,
            int $groupsCount
        ): void {
            $this->io->write(
                sprintf(
                    "Starting batch %d of %d with %d group(s)",
                    $batchNumber,
                    $totalBatches,
                    $groupsCount
                )
            );
        };

        /**
         * @param ProcessGroup[] $groups
         * @param int $batchNumber
         * @return void
         */
        $onBatchCompletedCallback = function (
            array $groups,
            int $batchNumber
        ) use ($globalCleanPackageManagerCache): void {
            $this->io->write(sprintf("Batch %d completed", $batchNumber));
            if ($globalCleanPackageManagerCache) {
                $this->io->writeComment('Clearing package manager cache');
                $result = $this->executor->execute(
                    'npm cache clear --force',
                    $this->outputHandler,
                    $this->config->rootConfig()->path()
                );
                $this->io->writeComment(sprintf('Finish clearing cache with result: %d', $result));
            }
        };

        $onAllBatchesCompletedCallback = function () {
            $this->io->writeInfo('All batches completed');
        };

        $processManager->run(
            $onBatchStartCallback,
            $onBatchCompletedCallback,
            $onAllBatchesCompletedCallback
        );

        return true;
    }

    private function isWipeNodeModulesEnabledGlobally(): bool
    {
        return $this->passedArguments->mode() === static::MODE_DEPLOYMENT
            || $this->passedArguments->forceDeleteNodeModules();
    }

    private function isCleanPackageManagerCacheEnabledGlobally(): bool
    {
        return $this->passedArguments->mode() === static::MODE_DEPLOYMENT
            || $this->passedArguments->clearPackageManagerCache();
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
     * @param string $packageFolder
     * @return bool
     */
    public function isWipePossible(string $packageFolder): bool
    {
        return true;
    }

    /**
     * @param Asset $asset
     * @return bool
     */
    private function maybeSkipAsset(Asset $asset): bool
    {
        $name = $asset->name();

        if ($this->locker->isLocked($asset)) {
            $this->io->write("Not processing '{$name}' because already processed.");

            return true;
        }

        if ($this->preCompiler->tryPrecompiled($asset)) {
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
     * Same as previous doDependencies but without
     * executing the process and without handling isolated cache
     *
     * @param Asset $asset
     * @param PackageManager $packageManager
     * @return bool|string
     */
    // phpcs:ignore Inpsyde.CodeQuality.ReturnTypeDeclaration.NoReturnType
    private function buildDependenciesCommand(
        Asset $asset,
        PackageManager $packageManager
    ) {

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

        return $command;
    }

    /**
     * @param Asset $asset
     * @param PackageManager $packageManager
     * @param RootConfig $rootConfig
     * @return bool
     * @deprecated
     */
    private function doDependencies(
        Asset $asset,
        PackageManager $packageManager,
        RootConfig $rootConfig
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
        $this->io->writeComment("{$action} dependencies for '{$name}' using {$cmdName}...");

        $command = $this->handleIsolatedCache(
            $packageManager,
            $asset,
            $rootConfig,
            $command,
            $cwd,
            $name
        );

        $exitCode = $this->executor->execute($command, $this->outputHandler, $cwd);

        return $exitCode === 0;
    }

    /**
     * @param PackageManager $packageManager
     * @param Asset $asset
     * @param RootConfig $rootConfig
     * @param string $command
     * @param string $cwd
     * @param string $assetName
     * @return string
     */
    private function handleIsolatedCache(
        PackageManager $packageManager,
        Asset $asset,
        RootConfig $rootConfig,
        string $command,
        string $cwd,
        string $assetName
    ): string {

        $isolated = $asset->isolatedCache() ?? $rootConfig->config()->isolatedCache() ?? false;
        if (!$isolated) {
            return $command;
        }

        $isYarn = $packageManager->isYarn();
        $cmdName = $packageManager->name();
        $cacheParam = $isYarn ? 'cache-folder' : 'cache';
        if (strpos($command, " --{$cacheParam}") !== false) {
            return $command;
        }

        $tempDir = $this->tempDir();
        $flushCache = $tempDir === null;
        $fullPath = $flushCache ? '' : "{$tempDir}/composer-asset-compiler/{$cmdName}/{$assetName}";

        try {
            $fullPath and $this->filesystem->ensureDirectoryExists($fullPath);
        } catch (\Throwable $throwable) {
            $flushCache = true;
        }

        if ($flushCache) {
            $this->flushCache($packageManager, $assetName, $cwd);

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
    private function wipeNodeModulesCommand(string $baseDir): string
    {
        $dir = rtrim($this->filesystem->normalizePath($baseDir), '/') . "/node_modules";
        return sprintf('composer filesystem-delete-folder --path="%s"', $dir);
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
        foreach ($scripts as $script) {
            $command = $packageManager->scriptCmd($script, $asset->env());
            $command and $assetCommands[] = $command;
        }

        $commandsStr = implode(' && ', $assetCommands);
        $name = $asset->name();
        $this->io->writeVerboseComment("Will compile '{$name}' using '{$commandsStr}'.");

        return $assetCommands;
    }

    /**
     * @param PackageManager $manager
     * @param string $asset
     * @param string $cwd
     * @return void
     */
    private function flushCache(PackageManager $manager, string $asset, string $cwd): void
    {
        $cmdName = $manager->name();
        $flushCmd = $manager->cleanCacheCmd();

        if (!$flushCmd) {
            $this->io->writeVerboseError(
                "Cache cleanup command not configured for {$cmdName}.",
                "Isolated cache not applicable for '{$asset}'."
            );

            return;
        }

        $this->io->writeVerbose(
            "Failed creating asset temporary directory.",
            "Will now clean cache executing '{$flushCmd}' "
            . "to ensure isolated cache for '{$asset}'."
        );

        $this->io->writeVerboseComment("Forcing {$cmdName} cache cleanup...");
        $out = null;
        if ($this->executor->execute($flushCmd, $out, $cwd) !== 0) {
            $this->io->writeVerboseError(
                "  {$cmdName} cache cleanup failed!",
                "  Isolated cache not applicable for '{$asset}'."
            );
        }
    }

    /**
     * @return string|null
     */
    private function tempDir(): ?string
    {
        if ($this->tempDir[0]) {
            return $this->tempDir[1];
        }

        $this->tempDir[0] = true;
        $sysDir = sys_get_temp_dir();
        $this->tempDir[1] = (is_dir($sysDir) && is_writable($sysDir))
            ? $this->filesystem->normalizePath($sysDir)
            : null;

        return $this->tempDir[1];
    }
}
