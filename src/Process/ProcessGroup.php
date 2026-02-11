<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Process;

use Symfony\Component\Process\Process;

// phpcs:disable Inpsyde.CodeQuality.NoAccessors.NoGetter
// phpcs:disable Inpsyde.CodeQuality.NoAccessors.NoSetter
final class ProcessGroup
{
    /**
     * @var Process
     */
    private $parentProcess;
    /**
     * @var Process[]
     */
    private $childProcesses = [];
    /**
     * @var bool
     */
    private $parentCompleted = false;
    /**
     * @var int
     */
    private $currentChildIndex = 0;

    /**
     * @var null|callable
     */
    private $onParentStartCallback;

    /**
     * @var null|callable
     */
    private $onChildStartCallback;

    /**
     * @var null|callable
     */
    private $onGroupCompletedCallback;

    /**
     * @var null|callable
     */
    private $onParentProcessErroredCallback;

    /**
     * @var null|callable
     */
    private $onChildProcessErroredCallback;

    public function __construct(
        Process $parentProcess,
        ?callable $onParentStartCallback = null,
        ?callable $onChildStartCallback = null,
        ?callable $onGroupCompletedCallback = null,
        ?callable $onParentProcessErroredCallback = null,
        ?callable $onChildProcessErroredCallback = null
    ) {

        $this->parentProcess = $parentProcess;
        $this->onParentStartCallback = $onParentStartCallback;
        $this->onGroupCompletedCallback = $onGroupCompletedCallback;
        $this->onChildStartCallback = $onChildStartCallback;
        $this->onParentProcessErroredCallback = $onParentProcessErroredCallback;
        $this->onChildProcessErroredCallback = $onChildProcessErroredCallback;
    }

    public function addChild(Process $process): self
    {
        $this->childProcesses[] = $process;
        return $this;
    }

    public function getParent(): Process
    {
        return $this->parentProcess;
    }

    public function hasChildren(): bool
    {
        return !empty($this->childProcesses);
    }

    public function isParentCompleted(): bool
    {
        return $this->parentCompleted;
    }

    public function markParentCompleted(): void
    {
        $this->parentCompleted = true;
    }

    public function getCurrentChild(): ?Process
    {
        return $this->childProcesses[$this->currentChildIndex] ?? null;
    }

    public function moveToNextChild(): void
    {
        $this->currentChildIndex++;
    }

    public function hasMoreChildren(): bool
    {
        return $this->currentChildIndex < count($this->childProcesses);
    }

    public function isComplete(): bool
    {
        return $this->parentCompleted && !$this->hasMoreChildren();
    }

    public function getOnParentStartCallback(): ?callable
    {
        return $this->onParentStartCallback;
    }

    public function getOnGroupCompletedCallback(): ?callable
    {
        return $this->onGroupCompletedCallback;
    }

    public function getOnChildStartCallback(): ?callable
    {
        return $this->onChildStartCallback;
    }

    public function getOnParentProcessErroredCallback(): ?callable
    {
        return $this->onParentProcessErroredCallback;
    }

    public function getOnChildProcessErroredCallback(): ?callable
    {
        return $this->onChildProcessErroredCallback;
    }
}
