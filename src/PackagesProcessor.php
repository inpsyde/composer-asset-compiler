<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process;

class PackagesProcessor
{
    /**
     * @var \Inpsyde\AssetsCompiler\Io
     */
    private $io;

    /**
     * @var \Inpsyde\AssetsCompiler\RootConfig
     */
    private $config;

    /**
     * @var \Inpsyde\AssetsCompiler\Commands
     */
    private $commands;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    private $executor;

    /**
     * @var \Inpsyde\AssetsCompiler\Locker
     */
    private $locker;

    /**
     * @var \Inpsyde\AssetsCompiler\ProcessFactory
     */
    private $processFactory;

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param \Inpsyde\AssetsCompiler\RootConfig $config
     * @param \Inpsyde\AssetsCompiler\Commands $commands
     * @param \Composer\Util\ProcessExecutor $executor
     * @param \Inpsyde\AssetsCompiler\ProcessFactory $processFactory
     * @param \Inpsyde\AssetsCompiler\Locker $locker
     * @return \Inpsyde\AssetsCompiler\PackagesProcessor
     */
    public static function new(
        Io $io,
        RootConfig $config,
        Commands $commands,
        ProcessExecutor $executor,
        ProcessFactory $processFactory,
        Locker $locker
    ): PackagesProcessor {

        return new static($io, $config, $commands, $executor, $processFactory, $locker);
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param \Inpsyde\AssetsCompiler\RootConfig $config
     * @param \Inpsyde\AssetsCompiler\Commands $commands
     * @param \Composer\Util\ProcessExecutor $executor
     * @param \Inpsyde\AssetsCompiler\ProcessFactory $processFactory
     * @param \Inpsyde\AssetsCompiler\Locker $locker
     */
    private function __construct(
        Io $io,
        RootConfig $config,
        Commands $commands,
        ProcessExecutor $executor,
        ProcessFactory $processFactory,
        Locker $locker
    ) {

        $this->io = $io;
        $this->config = $config;
        $this->commands = $commands;
        $this->executor = $executor;
        $this->processFactory = $processFactory;
        $this->locker = $locker;
    }

    /**
     * @param Package ...$packages
     * @return bool
     */
    public function process(Package ...$packages): bool
    {
        $timeout = 0;
        $processesData = [];
        $toWipe = [];
        $stopOnFailure = $this->config->stopOnFailure();
        $return = true;

        foreach ($packages as $package) {
            /**
             * @var string|null $name
             * @var string|null $path
             * @var bool|null $shouldWipe
             */
            [$name, $path, $shouldWipe] = $this->packageProcessInfo($package);
            if (!$name || !$path || $shouldWipe === null) {
                continue;
            }

            $this->io->writeVerboseComment(" Start installation dependencies for '{$name}'");
            $installedDeps = $this->doDependencies($package);

            if (!$installedDeps && $stopOnFailure) {
                return false;
            }

            $return = $installedDeps && $return;

            /**
             * @var string $command
             * @var int $timeout
             */
            [$command, $timeout] = $this->buildPackageScriptData($package, $timeout);

            // No script, we can lock already
            if (!$command) {
                $this->locker->isLocked($package);
                $shouldWipe and $this->wipeNodeModules($path);

                continue;
            }

            $processesData[] = [$package, $command, $timeout];
            $shouldWipe and $toWipe[$name] = $shouldWipe;
        }

        if (!$processesData) {
            $this->io->writeComment(" Nothing else to process.");

            return $return;
        }

        $processManager = new ProcessManager(
            function (string $type, string $buffer) {
                $this->outputHandler($type, $buffer);
            },
            $this->processFactory,
            $timeout,
            $this->config->maxProcesses(),
            $this->config->processesPoll()
        );

        /**
         * @var Package $package
         * @var string $command
         */
        foreach ($processesData as [$package, $command]) {
            $processManager = $processManager->pushPackageToProcess($package, $command);
        }

        $results = $processManager->execute($this->io, $stopOnFailure);

        return $this->handleResults($results, $toWipe, $timeout) && $return;
    }

    /**
     * @param Package $package
     * @return array{0:string, 1:string, 2:bool}|array{0:null, 1:null, 2:null}
     */
    private function packageProcessInfo(Package $package): array
    {
        $name = $package->name();
        $path = $package->path();

        if (!$name || !$path) {
            return [null, null, null];
        }

        if ($this->locker->isLocked($package)) {
            $this->io->writeVerboseComment(" Skipping '{$name}' because already compiled.");

            return [null, null, null];
        }

        $shouldWipe = $this->config->wipeAllowed($path);

        return [$name, $path, $shouldWipe];
    }

    /**
     * @return bool
     */
    private function doDependencies(Package $package): bool
    {
        $isUpdate = $package->isUpdate();
        $isInstall = $package->isInstall();

        if (!$isUpdate && !$isInstall) {
            return true;
        }

        $this->io->writeVerboseComment($isUpdate ? '  - updating...' : '  - installing...');

        $command = $isUpdate
            ? $this->commands->updateCmd($this->io)
            : $this->commands->installCmd($this->io);

        $outputHandler = function (string $type, string $buffer): void {
            $this->outputHandler($type, $buffer);
        };

        return $this->executor->execute($command ?? '', $outputHandler, $package->path()) === 0;
    }

    /**
     * @param Package $package
     * @param int $timeout
     * @return array{0:string, 1:int}
     */
    private function buildPackageScriptData(Package $package, int $timeout): array
    {
        [$script, $timeout] = $this->buildScriptCommand($package, $timeout);

        return [$script, $timeout];
    }

    /**
     * @param string $baseDir
     * @return bool|null
     */
    private function wipeNodeModules(string $baseDir): ?bool
    {
        $filesystem = $this->config->filesystem();
        $dir = rtrim((string)$filesystem->normalizePath($baseDir), '/') . "/node_modules";
        if (!is_dir($dir)) {
            $this->io->writeVerboseComment(" - '{$dir}' not found, nothing to wipe.");

            return null;
        }

        $this->io->writeVerboseComment(" - wiping '{$dir}'...");

        /** @var bool $doneWipe */
        $doneWipe = $filesystem->removeDirectory($dir);
        $doneWipe
            ? $this->io->writeVerboseInfo('   success!')
            : $this->io->writeVerboseError('   failed!');

        return $doneWipe;
    }

    /**
     * @param string $type
     * @param string $buffer
     * @return void
     */
    private function outputHandler(string $type, string $buffer): void
    {
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            Process::ERR === $type
                ? $this->io->writeVeryVerboseError('   ' . trim($line))
                : $this->io->writeVeryVerbose('   ' . trim($line));
        }
    }

    /**
     * @param ProcessResults $results
     * @param array<string, bool> $toWipe
     * @param int $timeout
     * @return bool
     */
    private function handleResults(
        ProcessResults $results,
        array $toWipe,
        int $timeout
    ): bool {

        if ($results->timedOut()) {
            $this->io->writeError(
                'Could not complete processing of packages because timeout of '
                . "{$timeout} seconds reached."
            );
        }

        $notExecuted = $results->notExecutedCount();
        if ($notExecuted > 0) {
            $total = $results->total();
            $this->io->writeError(
                "Processing for {$notExecuted} packages out of {$total} did NOT completed."
            );
        }

        $successes = $results->successes();
        while ($successes && !$successes->isEmpty()) {
            /** @var array{Process, Package} $success */
            $success = $successes->dequeue();
            /** @var Package $package */
            [, $package] = $success;
            $this->locker->lock($package);
            if (!empty($toWipe[$package->name()])) {
                $path = $package->path();
                $path and $this->wipeNodeModules($path);
            }
        }

        return $results->isSuccessful();
    }

    /**
     * @param Package $package
     * @param int $timeout
     * @return array{0:string, 1:int}
     */
    private function buildScriptCommand(Package $package, int $timeout): array
    {
        /** @var string[] $scripts */
        $scripts = $package->script();
        if (!$scripts) {
            return ['', $timeout];
        }

        $packageCommands = [];
        $packageEnv = $package->env();
        foreach ($scripts as $script) {
            $command = $this->commands->scriptCmd($script, $packageEnv);
            if ($command) {
                $packageCommands[] = $command;
                $timeout += 300;
            }
        }

        return [trim(implode(' && ', $packageCommands)), $timeout];
    }
}
