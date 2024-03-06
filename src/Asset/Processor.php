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

/*
 * phpcs:disable Inpsyde.CodeQuality.PropertyPerClassLimit
 */
class Processor
{
    /** @var callable */
    private $outputHandler;

    private ?PackageManager $defaultPackageManager = null;

    /**
     * @var array{bool, string|null}
     */
    private array $tempDir = [false, null];

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
            $filesystem,
            $outputHandler
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
     * @param Filesystem $filesystem
     * @param callable $outputHandler
     */
    private function __construct(
        private Io $io,
        private Config $config,
        private Finder $packageManagerFinder,
        private ProcessExecutor $executor,
        private ParallelManager $parallelManager,
        private Locker $locker,
        private PreCompilation\Handler $preCompiler,
        private Filesystem $filesystem,
        callable $outputHandler
    ) {

        $this->outputHandler = $outputHandler;
    }

    /**
     * @param \Iterator $assets
     * @return bool
     */
    public function process(\Iterator $assets): bool
    {
        $rootConfig = $this->config->rootConfig();
        if (!$rootConfig) {
            throw new \Error('Invalid root config.');
        }

        $toWipe = [];
        $manager = $this->parallelManager;
        foreach ($assets as $asset) {
            [$ok, $manager, $toWipe] = $this->processAsset($asset, $rootConfig, $manager, $toWipe);
            if ($ok === false) {
                return false;
            }
        }

        $results = $manager->execute($this->io, $rootConfig->stopOnFailure());

        return $this->handleResults($results, $toWipe);
    }

    /**
     * @param mixed $asset
     * @param RootConfig $rootConfig
     * @param ParallelManager $manager
     * @param array<string, bool> $toWipe
     * @param bool $stopOnFailure
     * @return list{bool, ParallelManager, array<string, bool>}
     */
    private function processAsset(
        mixed $asset,
        RootConfig $rootConfig,
        ParallelManager $manager,
        array $toWipe
    ): array {

        if (!($asset instanceof Asset) && $rootConfig->stopOnFailure()) {
            throw new \Exception('Invalid data to process.');
        }

        [$name, $path, $shouldWipe] = ($asset instanceof Asset)
            ? $this->assetProcessInfo($asset, $rootConfig)
            : [null, null, null];
        if (($name === null) || ($path === null) || ($shouldWipe === null)) {
            return [true, $manager, $toWipe];
        }

        /** @var Asset $asset */
        if ($this->maybeSkipAsset($asset)) {
            return [true, $manager, $toWipe];
        }

        try {
            $commands = $this->findCommandsForAsset($asset, $rootConfig);
        } catch (\Throwable) {
            $this->io->writeError("Could not find a package manager on the system.");

            return [false, $manager, $toWipe];
        }

        $installedDeps = $this->doDependencies($asset, $commands, $rootConfig);

        if (!$installedDeps) {
            return [!$rootConfig->stopOnFailure(), $manager, $toWipe];
        }

        $commandStrings = $this->buildScriptCommands($asset, $commands) ?? [];

        // No script, we can lock already
        if ($commandStrings === []) {
            $this->locker->lock($asset);
            $shouldWipe and $this->wipeNodeModules($path);

            return [true, $manager, $toWipe];
        }

        $manager = $manager->pushAssetToProcess($asset, ...$commandStrings);
        $shouldWipe and $toWipe[$name] = $shouldWipe;

        return [true, $manager, $toWipe];
    }

    /**
     * @param Asset $asset
     * @param RootConfig $root
     * @return list{non-empty-string,non-empty-string,bool}|list{null,null,null}
     */
    private function assetProcessInfo(Asset $asset, RootConfig $root): array
    {
        $name = $asset->name();
        $path = $asset->path() ?? '';

        if (($name === '') || ($path === '')) {
            return [null, null, null];
        }

        return [$name, $path, $root->isWipeAllowedFor($path)];
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
     * @param Asset $asset
     * @param PackageManager $packageManager
     * @param RootConfig $rootConfig
     * @return bool
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

        $cwd = $asset->path() ?? '';
        if (($cwd === '') || !is_dir($cwd)) {
            return false;
        }

        $command = $isUpdate
            ? $packageManager->updateCmd($this->io)
            : $packageManager->installCmd($this->io);

        if (($command === null) || ($command === '')) {
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
        if (str_contains($command, " --{$cacheParam}")) {
            return $command;
        }

        $tempDir = $this->tempDir();
        $flushCache = $tempDir === null;
        $fullPath = $flushCache ? '' : "{$tempDir}/composer-asset-compiler/{$cmdName}/{$assetName}";

        try {
            $fullPath and $this->filesystem->ensureDirectoryExists($fullPath);
        } catch (\Throwable) {
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
                $path = $asset->path() ?? '';
                ($path !== '') and $this->wipeNodeModules($path);
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
        foreach ($scripts as $script) {
            $command = $packageManager->scriptCmd($script, $asset->env()) ?? '';
            ($command !== '') and $assetCommands[] = $command;
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
