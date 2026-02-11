<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Process;

use Symfony\Component\Process\Process;

final class ParallelProcessManager
{
    /**
     * @var ProcessGroup[]
     */
    private $processGroups = [];

    /**
     * @var int
     */
    private $maxParallel;

    /**
     * @var Factory
     */
    private $processFactory;

    public function __construct(
        Factory $processFactory,
        int $maxParallel = 4
    ) {

        $this->processFactory = $processFactory;
        $this->maxParallel = $maxParallel;
    }

    public function addGroup(ProcessGroup $group): void
    {
        $this->processGroups[] = $group;
    }

    public function run(
        ?callable $onBatchStartCallback = null,
        ?callable $onBatchCompletedCallback = null,
        ?callable $onAllBatchesCompletedCallback = null
    ): void {

        $allGroups = $this->processGroups;
        $totalBatches = (int) ceil(count($allGroups) / $this->maxParallel);
        $batchNumber = 0;

        // Process groups in batches
        while (!empty($allGroups)) {
            $batchNumber++;

            // Take next batch of groups
            $currentBatch = array_splice($allGroups, 0, $this->maxParallel);
            $groupsCount = count($currentBatch);

            if ($onBatchStartCallback) {
                $onBatchStartCallback($currentBatch, $batchNumber, $totalBatches, $groupsCount);
            }

            $this->runBatch($currentBatch);

            /** @psalm-suppress RedundantCondition */
            if (!empty($allGroups) && $onBatchCompletedCallback !== null) {
                $onBatchCompletedCallback($currentBatch, $batchNumber, $totalBatches, $groupsCount);
            }
        }

        if ($onAllBatchesCompletedCallback !== null) {
            $onAllBatchesCompletedCallback();
        }
    }

    /**
     * @param ProcessGroup[] $groups
     * @param callable|null $onParentProcessStart
     * @param callable|null $onProcessStart
     * @return void
     */
    // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh,Inpsyde.CodeQuality.NestingLevel.MaxExceeded,Inpsyde.CodeQuality.FunctionLength.TooLong
    private function runBatch(
        array $groups,
        ?callable $onProcessStart = null
    ): void {

        $activeGroups = $groups;

        // Start all parent processes in this batch
        foreach ($activeGroups as $group) {
            $parentProcess = $group->getParent();
            if ($group->getOnParentStartCallback()) {
                ($group->getOnParentStartCallback())($parentProcess);
            }
            $parentProcess->start();
        }

        // Wait for all groups in batch to complete
        while (!empty($activeGroups)) {
            foreach ($activeGroups as $key => $group) {
                $parent = $group->getParent();

                // Check if parent is running
                if (!$group->isParentCompleted()) {
                    if (!$parent->isRunning()) {
                        if (!$parent->isSuccessful()) {
                            if ($group->getOnParentProcessErroredCallback()) {
                                ($group->getOnParentProcessErroredCallback())($parent);
                            }
                        }
                        $group->markParentCompleted();

                        // Start first child if exists
                        if ($group->hasMoreChildren()) {
                            $currentChild = $group->getCurrentChild();
                            if ($group->getOnChildStartCallback()) {
                                ($group->getOnChildStartCallback())($currentChild);
                            }
                            $currentChild?->start();
                        }
                    }
                    // phpcs:ignore Inpsyde.CodeQuality.NoElse.ElseFound
                } else {
                    // Parent completed, handle children sequentially
                    if ($group->hasMoreChildren()) {
                        $currentChild = $group->getCurrentChild();

                        if ($currentChild !== null) {
                            if (!$currentChild->isRunning()) {
                                if (!$currentChild->isSuccessful()) {
                                    throw new \RuntimeException(
                                        "Child process failed: " . $currentChild->getErrorOutput()
                                    );
                                }

                                // Move to next child
                                $group->moveToNextChild();

                                // Start next child if exists
                                if ($group->hasMoreChildren()) {
                                    $currentChild = $group->getCurrentChild();
                                    if ($group->getOnChildStartCallback()) {
                                        ($group->getOnChildStartCallback())($currentChild);
                                    }
                                    $currentChild?->start();
                                }
                            }
                        }
                    }

                    // Group is complete, remove it from active groups
                    if ($group->isComplete()) {
                        if ($group->getOnGroupCompletedCallback()) {
                            ($group->getOnGroupCompletedCallback())();
                        }
                        unset($activeGroups[$key]);
                    }
                }
            }

            usleep(100000); // 100ms - prevent tight loop
        }
    }

    public function createProcess(string $command, string $cwd): Process
    {
        return $this->processFactory->create($command, $cwd);
    }
}
