<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

use Composer\IO\IOInterface;

class Io
{
    private const SPACER = '    ';

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @param IOInterface $io
     */
    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * @return IOInterface
     */
    public function io(): IOInterface
    {
        return $this->io;
    }

    /**
     * @param string[] $messages
     */
    public function write(string ...$messages): void
    {
        foreach ($messages as $message) {
            $this->io->write(self::SPACER . $message);
        }
    }

    /**
     * @param string[] $messages
     */
    public function writeVerbose(string ...$messages): void
    {
        foreach ($messages as $message) {
            $this->io->write(self::SPACER . $message, true, IOInterface::VERBOSE);
        }
    }

    /**
     * @param string[] $messages
     */
    public function writeInfo(string ...$messages): void
    {
        $this->writeDecorated('info', false, ...$messages);
    }

    /**
     * @param string[] $messages
     */
    public function writeComment(string ...$messages): void
    {
        $this->writeDecorated('comment', false, ...$messages);
    }

    /**
     * @param string[] $messages
     */
    public function writeError(string ...$messages): void
    {
        $this->writeDecorated('error', false, ...$messages);
    }

    /**
     * @param string[] $messages
     */
    public function writeVerboseInfo(string ...$messages): void
    {
        $this->writeDecorated('info', true, ...$messages);
    }

    /**
     * @param string[] $messages
     */
    public function writeVerboseComment(string ...$messages): void
    {
        $this->writeDecorated('comment', true, ...$messages);
    }

    /**
     * @param string[] $messages
     */
    public function writeVerboseError(string ...$messages): void
    {
        $this->writeDecorated('error', true, ...$messages);
    }

    /**
     * @param string $tag
     * @param bool $verbose
     * @param string[] $messages
     */
    private function writeDecorated(string $tag, bool $verbose, string ...$messages): void
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
                $verbose ? IOInterface::VERBOSE : IOInterface::NORMAL
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
    <fg=green>                        </>
    <fg=green>        Inpsyde         </>
    <fg=green>                        </>

    <fg=magenta>Composer</> <fg=yellow>Assets</> <fg=magenta>Compiler</>
LOGO;
        // phpcs:enable

        $this->io->write("\n{$logo}\n");
    }
}
