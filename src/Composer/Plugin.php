<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Inpsyde\AssetsCompiler\Util\Factory;
use Inpsyde\AssetsCompiler\Util\Io;

/**
 * phpcs:disable Inpsyde.CodeQuality.NoAccessors
 *
 * @psalm-suppress MissingConstructor
 */
final class Plugin implements
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
     * @var IOInterface
     */
    private $io;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var int
     */
    private $mode = self::MODE_NONE;

    /**
     * @return array<string, list<array{string, int}>>
     *
     * @see Plugin::onAutorunBecauseInstall()
     * @see Plugin::onAutorunBecauseUpdate()
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
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return [CommandProvider::class => __CLASS__];
    }

    /**
     * @return array<BaseCommand>
     */
    public function getCommands(): array
    {
        return [new Command\CompileAssets(), new Command\AssetHash()];
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param Event $event
     * @return void
     */
    public function onAutorunBecauseInstall(Event $event): void
    {
        $factory = Factory::new($this->composer, $this->io, null, $event->isDevMode());
        if ($factory->rootConfig()->autoRun()) {
            $this->mode or $this->mode = self::MODE_COMPOSER_INSTALL;
            $this->run($factory);
        }
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
     * @param string $ignoreLock
     * @return void
     */
    public function runByCommand(
        ?string $env,
        bool $isDev,
        string $ignoreLock = ''
    ): void {

        $this->mode = self::MODE_COMMAND;
        $this->run(Factory::new($this->composer, $this->io, $env, $isDev, $ignoreLock));
    }

    /**
     * @param Factory $factory
     * @return void
     */
    private function run(Factory $factory): void
    {
        $exit = 0;
        $this->convertErrorsToExceptions();

        try {
            $io = $factory->io();
            $io->logo();
            $io->writeInfo('Starting...');
            $assets = $factory->assets();
            if (!$assets->valid()) {
                $io->write('Nothing to process.');

                return;
            }

            $factory->assetsProcessor()->process($assets) or $exit = 1;
        } catch (\Throwable $throwable) {
            $exit = 1;
            $this->handleThrowable($throwable, $io ?? null);
        } finally {
            restore_error_handler();
            $this->finalMessage($exit, $io ?? null);
            if (($exit > 0) || ($this->mode === self::MODE_COMMAND)) {
                exit($exit);
            }
        }
    }

    /**
     * @param \Throwable $throwable
     * @param Io|null $io
     * @return void
     */
    private function handleThrowable(\Throwable $throwable, ?Io $io): void
    {
        if (!$io) {
            fwrite(STDERR, "\n    " . $throwable->getMessage());
            fwrite(STDERR, "\n    " . $throwable->getTraceAsString());

            return;
        }

        $io->writeError($throwable->getMessage());
        $io->writeVerboseError(...explode("\n", $throwable->getTraceAsString()));
    }

    /**
     * @param int $exit
     * @param Io|null $io
     * @return void
     */
    private function finalMessage(int $exit, ?Io $io): void
    {
        if (!$io) {
            fwrite(($exit > 0) ? STDERR : STDOUT, ($exit > 0) ? "\n    Failed!" : "\n    Done.");

            return;
        }

        ($exit > 0) ? $io->writeError('Failed!') : $io->writeInfo('Done.');
    }

    /**
     * @return void
     */
    private function convertErrorsToExceptions(): void
    {
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

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // noop
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // noop
    }
}
