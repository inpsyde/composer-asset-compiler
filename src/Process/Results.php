<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Process;

use Inpsyde\AssetsCompiler\Asset\Asset;
use Symfony\Component\Process\Process;

final class Results
{
    private bool $timedOut = false;

    /**
     * @return Results
     */
    public static function newEmpty(): Results
    {
        $instance = new static(0, null, null);
        $instance->timedOut = false;

        return $instance;
    }

    /**
     * @param int $total
     * @param \SplQueue<array{Process, Asset}>|null $successful
     * @param \SplQueue<array{Process, Asset}>|null $erroneous
     * @return Results
     */
    public static function new(
        int $total,
        ?\SplQueue $successful,
        ?\SplQueue $erroneous
    ): Results {

        $instance = new static($total, $successful, $erroneous);
        $instance->timedOut = false;

        return $instance;
    }

    /**
     * @param int $total
     * @param \SplQueue<array{Process, Asset}>|null $successful
     * @param \SplQueue<array{Process, Asset}>|null $erroneous
     * @return Results
     */
    public static function newWithTimeout(
        int $total,
        ?\SplQueue $successful,
        ?\SplQueue $erroneous
    ): Results {

        $instance = new static($total, $successful, $erroneous);
        $instance->timedOut = true;

        return $instance;
    }

    /**
     * @param int $total
     * @param \SplQueue<array{Process, Asset}>|null $successful
     * @param \SplQueue<array{Process, Asset}>|null $erroneous
     */
    private function __construct(
        private int $total,
        private ?\SplQueue $successful,
        private ?\SplQueue $erroneous
    ) {
    }

    /**
     * @return bool
     */
    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /**
     * @return int
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return !$this->isEmpty()
            && !$this->timedOut()
            && !$this->notExecutedCount()
            && !$this->hasErrors();
    }

    /**
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->erroneous && !$this->erroneous->isEmpty();
    }

    /**
     * @return bool
     */
    public function hasSuccesses(): bool
    {
        return $this->successful && !$this->successful->isEmpty();
    }

    /**
     * @return int
     */
    public function notExecutedCount(): int
    {
        $successes = $this->successes();
        $errors = $this->errors();
        $successesCount = $successes ? $successes->count() : 0;
        $errorsCount = $errors ? $errors->count() : 0;

        return ($this->total > 0)
            ? ($this->total - ($successesCount + $errorsCount))
            : 0;
    }

    /**
     * @return \SplQueue<list{Process, Asset}>|null
     */
    public function successes(): ?\SplQueue
    {
        if (!$this->successful || $this->successful->isEmpty()) {
            return null;
        }

        $successes = clone $this->successful;
        $successes->rewind();

        return $successes;
    }

    /**
     * @return \SplQueue<array{Process, Asset}>|null
     */
    public function errors(): ?\SplQueue
    {
        if (!$this->erroneous || $this->erroneous->isEmpty()) {
            return null;
        }

        $errors = clone $this->erroneous;
        $errors->rewind();

        return $errors;
    }
}
