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
     * @var int
     */
    private $executed = 0;

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
        return ($this->total > 0) ? $this->total - $this->executed : 0;
    }

    /**
     * @return \SplQueue|null
     */
    public function successes(): ?\SplQueue
    {
        if (!$this->successful || $this->successful->isEmpty()) {
            return null;
        }

        $this->successful->rewind();

        return $this->successful;
    }

    /**
     * @return \SplQueue|null
     */
    public function errors(): ?\SplQueue
    {
        if (!$this->erroneous || $this->erroneous->isEmpty()) {
            return null;
        }

        $this->erroneous->rewind();

        return $this->erroneous;
    }
}
