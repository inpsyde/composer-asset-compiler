<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests;

use Composer\IO\BaseIO;

class TestIo extends BaseIO
{
    /** @var list<string>  */
    public array $outputs = [];

    /** @var list<string> */
    public array $errors = [];

    /**
     * @param int $verbosity
     */
    public function __construct(
        private int $verbosity = self::NORMAL
    ) {
    }

    /**
     * @param string $regex
     * @return bool
     */
    public function hasOutputThatMatches(string $regex): bool
    {
        foreach ($this->outputs as $output) {
            if (preg_match($regex, $output)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $regex
     * @return bool
     */
    public function hasErrorThatMatches(string $regex): bool
    {
        foreach ($this->errors as $output) {
            if (preg_match($regex, $output)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return false
     */
    public function isInteractive(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isVerbose(): bool
    {
        return $this->verbosity > self::NORMAL;
    }

    /**
     * @return bool
     */
    public function isVeryVerbose(): bool
    {
        return $this->verbosity > self::VERBOSE;
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->verbosity > self::VERY_VERBOSE;
    }

    /**
     * @return false
     */
    public function isDecorated(): bool
    {
        return false;
    }

    /**
     * @param string|list<string> $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function write(
        mixed $messages,
        bool $newline = true,
        int $verbosity = self::NORMAL
    ): void {

        if ($verbosity > $this->verbosity) {
            return;
        }
        foreach ((array) $messages as $message) {
            $this->outputs[] = $message;
        }
    }

    /**
     * @param string|list<string> $messages
     * @param bool $newline
     * @param int $verbosity
     * @return void
     */
    public function writeError(
        mixed $messages,
        bool $newline = true,
        int $verbosity = self::NORMAL
    ): void {

        if ($verbosity > $this->verbosity) {
            return;
        }
        foreach ((array) $messages as $message) {
            $this->errors[] = $message;
        }
    }

    /**
     * @param string|list<string> $messages
     * @param bool $newline
     * @param int|null $size
     * @param int $verbosity
     * @return void
     */
    public function overwrite(
        mixed $messages,
        bool $newline = true,
        ?int $size = null,
        int $verbosity = self::NORMAL
    ): void {

        // TODO: Implement overwrite() method.
    }

    /**
     * @param string|list<string> $messages
     * @param bool $newline
     * @param int|null $size
     * @param int $verbosity
     * @return void
     */
    public function overwriteError(
        mixed $messages,
        bool $newline = true,
        ?int $size = null,
        int $verbosity = self::NORMAL
    ): void {

        // TODO: Implement overwriteError() method.
    }

    /**
     * @param string $question
     * @param mixed|null $default
     * @return mixed
     */
    public function ask(string $question, mixed $default = null): mixed
    {
        return $default;
    }

    /**
     * @param string $question
     * @param mixed $default
     * @return bool
     */
    public function askConfirmation(string $question, bool $default = true): bool
    {
        return $default;
    }

    /**
     * @param string $question
     * @param callable $validator
     * @param int|null $attempts
     * @param mixed $default
     * @return mixed
     */
    public function askAndValidate(
        string $question,
        callable $validator,
        ?int $attempts = null,
        mixed $default = null
    ): mixed {

        return $default;
    }

    /**
     * @param string $question
     * @return string|null
     */
    public function askAndHideAnswer(string $question): ?string
    {
        return null;
    }

    /**
     * @param string $question
     * @param array $choices
     * @param mixed $default
     * @param mixed $attempts
     * @param string $errorMessage
     * @param bool $multiselect
     * @return mixed
     */
    public function select(
        string $question,
        array $choices,
        mixed $default,
        mixed $attempts = false,
        string $errorMessage = 'Value "%s" is invalid',
        bool $multiselect = false
    ): mixed {

        return $default;
    }
}
