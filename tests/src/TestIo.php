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

/*
 * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
 * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
 */
class TestIo extends BaseIO
{
    /**
     * @var int
     */
    private $verbosity;

    /**
     * @var list<string>
     */
    public $outputs = [];

    /**
     * @var list<string>
     */
    public $errors = [];

    public function __construct(int $verbosity = self::NORMAL)
    {
        $this->verbosity = $verbosity;
    }

    public function hasOutputThatMatches(string $regex): bool
    {
        foreach ($this->outputs as $output) {
            if (preg_match($regex, $output)) {
                return true;
            }
        }

        return false;
    }

    public function hasErrorThatMatches(string $regex): bool
    {
        foreach ($this->errors as $output) {
            if (preg_match($regex, $output)) {
                return true;
            }
        }

        return false;
    }

    public function isInteractive()
    {
        return false;
    }

    public function isVerbose()
    {
        return $this->verbosity > self::NORMAL;
    }

    public function isVeryVerbose()
    {
        return $this->verbosity > self::VERBOSE;
    }

    public function isDebug()
    {
        return $this->verbosity > self::VERY_VERBOSE;
    }

    public function isDecorated()
    {
        return false;
    }

    public function write($messages, $newline = true, $verbosity = self::NORMAL)
    {
        if ($verbosity > $this->verbosity) {
            return;
        }
        foreach ((array)$messages as $message) {
            $this->outputs[] = $message;
        }
    }

    public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
    {
        if ($verbosity > $this->verbosity) {
            return;
        }
        foreach ((array)$messages as $message) {
            $this->errors[] = $message;
        }
    }

    public function overwrite($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        // TODO: Implement overwrite() method.
    }

    public function overwriteError($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        // TODO: Implement overwriteError() method.
    }

    public function ask($question, $default = null)
    {
        return $default;
    }

    public function askConfirmation($question, $default = true)
    {
        return $default;
    }

    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        return $default;
    }

    public function askAndHideAnswer($question)
    {
        return null;
    }

    public function select(
        $question,
        $choices,
        $default,
        $attempts = false,
        $errorMessage = 'Value "%s" is invalid',
        $multiselect = false
    ) {

        return $default;
    }
}
