<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\IO\ConsoleIO;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
class SilentConsoleIo extends ConsoleIO
{
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
        parent::__construct($io->input, $io->output, $io->helperSet);
    }

    /**
     * @return bool
     */
    public function isVerbose(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isVeryVerbose(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return false;
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function write(
        mixed $messages,
        bool $newline = true,
        int $verbosity = self::NORMAL
    ): void {
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function writeError(
        mixed $messages,
        bool $newline = true,
        int $verbosity = self::NORMAL
    ): void {
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function writeRaw(
        mixed $messages,
        bool $newline = true,
        int $verbosity = self::NORMAL
    ): void {
    }

    /**
     * @param array|string $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function writeErrorRaw(
        mixed $messages,
        bool $newline = true,
        int $verbosity = self::NORMAL
    ): void {
    }
}
