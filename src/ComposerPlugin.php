<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Symfony\Component\Process\Process;

/**
 * phpcs:disable Inpsyde.CodeQuality.NoAccessors
 *
 * @psalm-suppress MissingConstructor
 */
final class ComposerPlugin implements
    PluginInterface,
    EventSubscriberInterface,
    Capable,
    CommandProvider
{

    private const MODE_NONE = 0;
    private const MODE_COMMAND = 1;
    private const MODE_COMPOSER_INSTALL = 4;
    private const MODE_COMPOSER_UPDATE = 8;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var int
     */
    private $mode = self::MODE_NONE;

    /**
     * @return array
     *
     * @see ComposerPlugin::onAutorunBecauseInstall()
     * @see ComposerPlugin::onAutorunBecauseUpdate()
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'post-install-cmd' => [
                ['onAutorunBecauseInstall', 0],
            ],
            'post-update-cmd' => [
                ['onAutorunBecauseUpdate', 0],
            ],
        ];
    }

    /**
     * @return array
     */
    public function getCapabilities(): array
    {
        return [CommandProvider::class => __CLASS__];
    }

    /**
     * @return array
     */
    public function getCommands(): array
    {
        return [new CompileAssetsCommand()];
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     *
     * @psalm-suppress MissingReturnType
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = new Io($io);
    }

    /**
     * @param Event $event
     */
    public function onAutorunBecauseInstall(Event $event): void
    {
        $this->config = $this->initConfig(null, (bool)$event->isDevMode());
        if (!$this->config->autoRun()) {
            return;
        }

        $this->mode or $this->mode = self::MODE_COMPOSER_INSTALL;
        $this->run();
    }

    /**
     * @param Event $event
     */
    public function onAutorunBecauseUpdate(Event $event): void
    {
        $this->mode = self::MODE_COMPOSER_UPDATE;
        $this->onAutorunBecauseInstall($event);
    }

    /**
     * @param string|null $env
     * @param bool $isDev
     */
    public function runByCommand(?string $env, bool $isDev): void
    {
        $this->config = $this->initConfig($env, $isDev);
        $this->mode = self::MODE_COMMAND;
        $this->run();
    }

    /**
     * @param string|null $env
     * @param bool $isDev
     * @return Config
     */
    private function initConfig(?string $env, bool $isDev): Config
    {
        if ($env === null) {
            $env = EnvResolver::readEnv('COMPOSER_ASSETS_COMPILER');
        }

        /** @var RootPackageInterface $rootPackage */
        $rootPackage = $this->composer->getPackage();

        return new Config(
            $rootPackage,
            new EnvResolver($env, $isDev),
            new Filesystem(),
            $this->io
        );
    }

    /**
     * @return void
     */
    private function run(): void
    {
        $this->convertErrorsToExceptions();
        $exit = 0;

        try {
            $this->io->logo();
            $this->io->writeInfo('', 'starting...', '');

            /** @var Package[] $packages */
            $packages = $this->findPackages();
            if (!$packages) {
                return;
            }

            /** @var Package $firstPackage */
            $firstPackage = reset($packages);

            /** @var string $path */
            $path = $firstPackage->path();

            $commands = $this->config->commands($path);

            if (!$commands->isValid()) {
                throw new \Exception(
                    'Could not found a valid package manager. '
                    . 'Make sure either Yarn or npm are installed.'
                );
            }

            $this->processPackages($commands, ...$packages)
                ? $this->io->writeInfo('', 'done.', '')
                : $exit = 1;
        } catch (\Throwable $throwable) {
            $exit = 1;
            $this->io->writeError($throwable->getMessage());
            $this->io->writeVerboseError(...explode("\n", $throwable->getTraceAsString()));
        } finally {
            restore_error_handler();
            if ($exit || $this->mode === self::MODE_COMMAND) {
                exit($exit);
            }
        }
    }

    /**
     * @return array<int,Package>
     */
    private function findPackages(): array
    {
        /** @var RepositoryManager $manager */
        $manager = $this->composer->getRepositoryManager();

        /** @var RepositoryInterface $repo */
        $repo = $manager->getLocalRepository();

        /** @var InstallationManager $installationManager */
        $installationManager = $this->composer->getInstallationManager();

        /** @var RootPackageInterface $root */
        $root = $this->composer->getPackage();

        $factory = new PackageFactory(
            $this->config->envResolver(),
            $this->config->filesystem(),
            $installationManager
        );

        /** @var array<string,Package> $packages */
        $packages = $this->config->packagesFinder()->find(
            $repo,
            $root,
            $factory,
            $this->config->autoDiscover()
        );

        if (!$packages) {
            $this->io->writeVerboseComment('Nothing to process.');

            return [];
        }

        /** @var array<int,Package> $packages */
        $packages = array_values($packages);

        return $packages;
    }

    /**
     * @param Commands $commands
     * @param Package ...$packages
     * @return bool
     */
    private function processPackages(Commands $commands, Package ...$packages): bool
    {
        $locker = new Locker($this->io, $this->config->envResolver()->env());

        $timeout = 0;
        $processesData = [];
        foreach ($packages as $package) {
            if ($locker->isLocked($package)) {
                $name = $package->name();
                $this->io->writeVerboseComment(" Skipping '{$name}' because already compiled.");
                continue;
            }

            [$package, $command, $shouldWipe, $timeout] = $this->buildPackageCommandData(
                $package,
                $commands,
                $timeout
            );

            $processesData[] = [$package, $command, $shouldWipe, $timeout];
        }

        if (!$processesData) {
            $this->io->writeComment(" Nothing to process.");

            return true;
        }

        $processManager = new ProcessManager(
            function (string $type, string $buffer) {
                $this->outputHandler($type, $buffer);
            },
            $timeout,
            $this->config->maxProcesses(),
            $this->config->processesPoll()
        );

        $toWipe = [];

        /**
         * @var \Inpsyde\AssetsCompiler\Package $package
         * @var string $command
         * @var bool $shouldWipe
         */
        foreach ($processesData as [$package, $command, $shouldWipe]) {
            $processManager = $processManager->pushPackageToProcess($package, $command);
            $toWipe[$package->name()] = $shouldWipe;
        }

        $results = $processManager->execute($this->io, $this->config->stopOnFailure());

        return $this->handleResults($results, $locker, $toWipe, $timeout);
    }

    /**
     * @param \Inpsyde\AssetsCompiler\ProcessResults $results
     * @param \Inpsyde\AssetsCompiler\Locker $locker
     * @param array<string, bool> $toWipe
     * @param int $timeout
     * @return bool
     */
    private function handleResults(
        ProcessResults $results,
        Locker $locker,
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
            $this->io->writeError("{$notExecuted} packages out of {$total} were NOT processed.");
        }

        $successes = $results->successes();
        while ($successes && !$successes->isEmpty()) {
            /** @var Package $package */
            [, $package] = $successes->dequeue();
            $locker->lock($package);
            if (!empty($toWipe[$package->name()])) {
                $this->wipeNodeModules($package->path());
            }
        }

        return $results->isSuccessful();
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Package $package
     * @param \Inpsyde\AssetsCompiler\Commands $commands
     * @param int $timeout
     * @return array{0:string, 1:string, 2:bool, 3:int}
     */
    private function buildPackageCommandData(
        Package $package,
        Commands $commands,
        int $timeout
    ): array {

        $shouldWipe = $this->config->wipeAllowed($package->path() ?? '');

        $timeout += 300;
        $command = $this->buildDependenciesCommand($package, $commands);
        if ($command) {
            [$script, $timeout] = $this->buildScriptCommand($package, $commands, $timeout);
            $script and $command .= " && {$script}";
        }

        return [$package, $command, $shouldWipe, $timeout];
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Package $package
     * @param \Inpsyde\AssetsCompiler\Commands $commands
     * @return string
     */
    private function buildDependenciesCommand(Package $package, Commands $commands): string
    {
        $isUpdate = $package->isUpdate();
        $isInstall = $package->isInstall();

        if (!$isUpdate && !$isInstall) {
            return '';
        }

        return $isUpdate
            ? ($commands->updateCmd($this->io) ?? '')
            : ($commands->installCmd($this->io) ?? '');
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Package $package
     * @param \Inpsyde\AssetsCompiler\Commands $commands
     * @param int $timeout
     * @return array{0:string, 1:int}
     */
    private function buildScriptCommand(Package $package, Commands $commands, int $timeout): array
    {
        /** @var string[] $scripts */
        $scripts = $package->script();
        if (!$scripts) {
            return ['', $timeout];
        }

        $packageCommands = [];
        $packageEnv = $package->env();
        foreach ($scripts as $script) {
            $command = $commands->scriptCmd($script, $packageEnv);
            if ($command) {
                $packageCommands[] = $command;
                $timeout += 300;
            }
        }

        return [implode(' && ', $packageCommands), $timeout];
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
     * @return void
     */
    private function convertErrorsToExceptions(): void
    {
        /** @psalm-suppress MixedArgumentTypeCoercion */
        set_error_handler(
            static function (int $severity, string $msg, string $file = '', int $line = 0): void {
                if ($file && $line) {
                    $msg = rtrim($msg, '. ') . ", in {$file} line {$line}.";
                }

                throw new \Exception($msg, $severity);
            },
            E_ALL
        );
    }
}
