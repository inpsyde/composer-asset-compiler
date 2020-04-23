<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

final class ProcessResults
{
    /**
     * @var int
     */
    private $total = 0;

    /**
     * @var \SplQueue|null
     */
    private $successful;

    /**
     * @var \SplQueue|null
     */
    private $erroneous;

    /**
     * @var bool
     */
    private $timedOut = false;

    /**
     * @return \Inpsyde\AssetsCompiler\ProcessResults
     */
    public static function empty(): ProcessResults
    {
        $instance = new static(0, null, null);
        $instance->timedOut = false;

        return $instance;
    }

    /**
     * @param int $total
     * @param \SplQueue|null $successful
     * @param \SplQueue|null $erroneous
     * @return \Inpsyde\AssetsCompiler\ProcessResults
     */
    public static function new(
        int $total,
        ?\SplQueue $successful,
        ?\SplQueue $erroneous
    ): ProcessResults {

        $instance = new static($total, $successful, $erroneous);
        $instance->timedOut = false;

        return $instance;
    }

    /**
     * @param int $total
     * @param \SplQueue|null $successful
     * @param \SplQueue|null $erroneous
     * @return \Inpsyde\AssetsCompiler\ProcessResults
     */
    public static function timeout(
        int $total,
        ?\SplQueue $successful,
        ?\SplQueue $erroneous
    ): ProcessResults {

        $instance = new static($total, $successful, $erroneous);
        $instance->timedOut = true;

        return $instance;
    }

    /**
     */
    private function __construct(
        int $total,
        ?\SplQueue $successful,
        ?\SplQueue $erroneous
    ) {

        $this->total = $total;
        $this->successful = $successful;
        $this->erroneous = $erroneous;
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
     * @return \SplQueue|null
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
     * @return \SplQueue|null
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
