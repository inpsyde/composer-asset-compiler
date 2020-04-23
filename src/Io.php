<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Io
{
    private const SPACER = '    ';

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var bool|null
     */
    private $quiet;

    /**
     * @param IOInterface $io
     */
    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * @return bool
     */
    public function isVerbose(): bool
    {
        return (bool)$this->io->isVerbose();
    }

    /**
     * @return bool
     */
    public function isVeryVerbose(): bool
    {
        return (bool)$this->io->isVeryVerbose();
    }

    /**
     * @return bool
     */
    public function isVeryVeryVerbose(): bool
    {
        return (bool)$this->io->isDebug();
    }

    /**
     * @return bool
     */
    public function isQuiet(): bool
    {
        if (is_bool($this->quiet)) {
            return $this->quiet;
        }

        if (!($this->io instanceof ConsoleIO)) {
            $this->quiet = false;

            return false;
        }

        $isQuietChecker = \Closure::bind(
            function (): bool {
                $output = $this->output ?? null;
                if ($output instanceof OutputInterface) {
                    return (bool)$output->isQuiet();
                }

                return false;
            },
            $this->io,
            ConsoleIO::class
        );

        /** @var bool $isQuiet */
        $isQuiet = $isQuietChecker ? $isQuietChecker() : false;
        $this->quiet = $isQuiet;

        return $isQuiet;
    }

    /**
     * @return bool
     */
    public function isInteractive(): bool
    {
        return (bool)$this->io->isInteractive();
    }

    /**
     * @param string ...$messages
     */
    public function write(string ...$messages): void
    {
        foreach ($messages as $message) {
            $this->io->write(self::SPACER . $message);
        }
    }

    /**
     * @param string ...$messages
     */
    public function writeVerbose(string ...$messages): void
    {
        foreach ($messages as $message) {
            $this->io->write(self::SPACER . $message, true, IOInterface::VERBOSE);
        }
    }

    /**
     * @param string ...$messages
     */
    public function writeVeryVerbose(string ...$messages): void
    {
        foreach ($messages as $message) {
            $this->io->write(self::SPACER . $message, true, IOInterface::VERY_VERBOSE);
        }
    }

    /**
     * @param string ...$messages
     */
    public function writeInfo(string ...$messages): void
    {
        $this->writeDecorated('info', IOInterface::NORMAL, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeComment(string ...$messages): void
    {
        $this->writeDecorated('comment', IOInterface::NORMAL, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeError(string ...$messages): void
    {
        $this->writeDecorated('error', IOInterface::NORMAL, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeVerboseInfo(string ...$messages): void
    {
        $this->writeDecorated('info', IOInterface::VERBOSE, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeVeryVerboseInfo(string ...$messages): void
    {
        $this->writeDecorated('info', IOInterface::VERY_VERBOSE, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeVerboseComment(string ...$messages): void
    {
        $this->writeDecorated('comment', IOInterface::VERBOSE, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeVeryVerboseComment(string ...$messages): void
    {
        $this->writeDecorated('comment', IOInterface::VERY_VERBOSE, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeVerboseError(string ...$messages): void
    {
        $this->writeDecorated('error', IOInterface::VERBOSE, ...$messages);
    }

    /**
     * @param string ...$messages
     */
    public function writeVeryVerboseError(string ...$messages): void
    {
        $this->writeDecorated('error', IOInterface::VERY_VERBOSE, ...$messages);
    }

    /**
     * @param string $tag
     * @param int $verbosity
     * @param string ...$messages
     */
    private function writeDecorated(string $tag, int $verbosity, string ...$messages): void
    {
        $method = 'write';
        $closeTag = $tag;
        if ($tag === 'error') {
            $method = 'writeError';
            $tag = 'fg=red';
            $closeTag = '';
        }

        foreach ($messages as $message) {
            $this->io->{$method}(
                self::SPACER . "<{$tag}>$message</{$closeTag}>",
                true,
                $verbosity
            );
        }
    }

    /**
     * @return void
     */
    public function logo(): void
    {
        // phpcs:disable
        $logo = <<<LOGO
    <fg=white;bg=green>                        </>
    <fg=white;bg=green>        Inpsyde         </>
    <fg=white;bg=green>                        </>

    <fg=magenta>Composer</> <fg=yellow>Assets</> <fg=magenta>Compiler</>
LOGO;
        // phpcs:enable

        $this->io->write("\n{$logo}\n");
    }
}
