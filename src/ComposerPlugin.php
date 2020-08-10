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
use Composer\Util\ProcessExecutor;

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
     * @var RootConfig
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
     * @return RootConfig
     */
    private function initConfig(?string $env, bool $isDev): RootConfig
    {
        if ($env === null) {
            $env = EnvResolver::readEnv('COMPOSER_ASSETS_COMPILER');
        }

        /** @var RootPackageInterface $rootPackage */
        $rootPackage = $this->composer->getPackage();

        return new RootConfig(
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

            /** @var array<int, Package> $packages */
            $packages = $this->findPackages();
            if (!$packages) {
                return;
            }

            $firstPackage = $packages[0];
            $path = (string)$firstPackage->path();
            $executor = new ProcessExecutor();
            $commands = $this->config->commands($path, $executor);

            if (!$commands->isValid()) {
                throw new \Exception(
                    'Could not found a valid package manager. '
                    . 'Make sure either Yarn or npm are installed.'
                );
            }

            $processor = PackagesProcessor::new(
                $this->io,
                $this->config,
                $commands,
                $executor,
                new ProcessFactory(),
                new Locker($this->io, $this->config->envResolver()->env())
            );

            $processor->process(...$packages) or $exit = 1;
        } catch (\Throwable $throwable) {
            $exit = 1;
            $this->io->writeError($throwable->getMessage());
            $this->io->writeVerboseError(...explode("\n", $throwable->getTraceAsString()));
        } finally {
            ($exit > 0)
                ? $this->io->writeError('', 'failed!', '')
                : $this->io->writeInfo('', 'done.', '');
            restore_error_handler();
            if (($exit > 0) || ($this->mode === self::MODE_COMMAND)) {
                exit($exit);
            }
        }
    }

    /**
     * @return array<int, Package>
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
        /** @var \Composer\Config $config */
        $config = $this->composer->getConfig();
        /** @var \Composer\Config\ConfigSourceInterface $source */
        $source = $config->getConfigSource();

        $factory = new PackageFactory(
            $this->config->envResolver(),
            $this->config->filesystem(),
            $installationManager,
            dirname((string)$source->getName())
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
     * @return void
     */
    private function convertErrorsToExceptions(): void
    {
        /** @psalm-suppress InvalidArgument */
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
