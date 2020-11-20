<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\IO\ConsoleIO;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 *
 * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
 * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
 */
class SilentConsoleIo extends ConsoleIO
{
    /**
     * @var ConsoleIO
     */
    private $consoleIo;

    /**
     * @param ConsoleIO $io
     * @return SilentConsoleIo
     */
    public static function new(ConsoleIO $io): SilentConsoleIo
    {
        return new self($io);
    }

    /**
     * @param ConsoleIO $io
     */
    private function __construct(ConsoleIO $io)
    {
        $this->consoleIo = $io;
        parent::__construct($io->input, $io->output, $io->helperSet);
    }

    /**
     * @return bool
     */
    public function isVerbose()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isVeryVerbose()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isDebug()
    {
        return false;
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function write($messages, $newline = true, $verbosity = self::NORMAL)
    {
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
    {
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function writeRaw($messages, $newline = true, $verbosity = self::NORMAL)
    {
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function writeErrorRaw($messages, $newline = true, $verbosity = self::NORMAL)
    {
    }
}
