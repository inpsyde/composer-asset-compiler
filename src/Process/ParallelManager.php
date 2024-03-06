<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Process;

use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Symfony\Component\Process\Process;

class ParallelManager
{
    /** @var callable */
    private $outputHandler;
    /** @var positive-int */
    private int $maxParallel;
    /** @var positive-int  */
    private int $poll;
    private int $timeoutIncrement;
    /** @var \SplQueue<array{Process, Asset}> */
    private \SplQueue $stack;
    private int $timeout = 0;
    private float $executionStarted;
    /** @var array<string, string> */
    private array $commands;
    private int $total = 0;

    /**
     * @param callable $outputHandler
     * @param Factory $factory
     * @param int $maxParallel
     * @param int $poll
     * @param int $timeoutIncrement
     * @return ParallelManager
     */
    public static function new(
        callable $outputHandler,
        Factory $factory,
        int $maxParallel = 4,
        int $poll = 100000, # microseconds, default: 100000 = 1/10 of second
        int $timeoutIncrement = 300
    ): ParallelManager {

        return new self($factory, $outputHandler, $maxParallel, $poll, $timeoutIncrement);
    }

    /**
     * @param Factory $factory
     * @param callable $outputHandler
     * @param int $maxParallel
     * @param int $poll
     * @param int $timeoutIncrement
     */
    private function __construct(
        private Factory $factory,
        callable $outputHandler,
        int $maxParallel,
        int $poll,
        int $timeoutIncrement
    ) {

        $this->outputHandler = $outputHandler;
        $this->maxParallel = ($maxParallel >= 1) ? $maxParallel : 4;
        // sanity: between 0.005 and 2 seconds
        $this->poll = min(max($poll, 5000), 2000000);
        // sanity: between 30 and 3600 seconds
        $this->timeoutIncrement = min(max($timeoutIncrement, 30), 3600);

        $this->resetStatus();
    }

    /**
     * @param Asset $asset
     * @param string $command
     * @param string ...$commands
     * @return static
     */
    public function pushAssetToProcess(
        Asset $asset,
        string $command,
        string ...$commands
    ): ParallelManager {

        array_unshift($commands, $command);
        $command = implode(' && ', $commands);

        $process = $this->factory->create($command, (string) $asset->path());
        $this->commands[$asset->name()] = $command;
        $this->stack->enqueue([$process, $asset]);
        $this->total++;
        $this->timeout += ($this->timeoutIncrement * count($commands));

        return $this;
    }

    /**
     * @param Io $io
     * @param bool $stopOnFailure
     * @return Results
     */
    public function execute(Io $io, bool $stopOnFailure): Results
    {
        if ($this->total <= 0) {
            return Results::newEmpty();
        }

        $this->executionStarted = microtime(true);

        /** @var \SplQueue<array{Process, Asset}> $running */
        $running = new \SplQueue();
        /** @var \SplQueue<array{Process, Asset}> $successful */
        $successful = new \SplQueue();
        /** @var \SplQueue<array{Process, Asset}> $erroneous */
        $erroneous = new \SplQueue();

        [$timedOut, $successful, $erroneous] = $this->executeProcesses(
            $io,
            $stopOnFailure,
            $running,
            $successful,
            $erroneous
        );

        $results = $timedOut
            ? Results::newWithTimeout($this->total, $successful, $erroneous)
            : Results::new($this->total, $successful, $erroneous);

        $this->resetStatus();

        return $results;
    }

    /**
     * @return void
     */
    private function resetStatus(): void
    {
        /** @var \SplQueue<array{Process, Asset}> $this->stack */
        $this->stack = new \SplQueue();
        $this->total = 0;
        $this->executionStarted = 0.0;
        $this->commands = [];
    }

    /**
     * @param Io $io
     * @param bool $stopOnFailure
     * @param \SplQueue<array{Process, Asset}> $running
     * @param \SplQueue<array{Process, Asset}> $successful
     * @param \SplQueue<array{Process, Asset}> $erroneous
     * @return array{
     *  bool,
     *  \SplQueue<array{Process, Asset}>,
     *  \SplQueue<array{Process, Asset}>,
     *  \SplQueue<array{Process, Asset}>
     * }
     */
    private function executeProcesses(
        Io $io,
        bool $stopOnFailure,
        \SplQueue $running,
        \SplQueue $successful,
        \SplQueue $erroneous
    ): array {

        $running = $this->startProcessesFormStack($io, $running);

        [$stillRunning, $successful, $erroneous] = $this->checkRunningProcesses(
            $io,
            $stopOnFailure,
            $running,
            $successful,
            $erroneous
        );

        if ($this->checkTimedOut()) {
            return [true, $successful, $erroneous, $stillRunning];
        }

        if ($stopOnFailure && !$erroneous->isEmpty()) {
            return [false, $successful, $erroneous, $stillRunning];
        }

        while (!$stillRunning->isEmpty() || !$this->stack->isEmpty()) {
            [, $successful, $erroneous, $stillRunning] = $this->executeProcesses(
                $io,
                $stopOnFailure,
                $stillRunning,
                $successful,
                $erroneous
            );
        }

        return [false, $successful, $erroneous, $stillRunning];
    }

    /**
     * @return bool
     */
    private function checkTimedOut(): bool
    {
        return (microtime(true) - $this->executionStarted) > $this->timeout;
    }

    /**
     * @param Io $io
     * @param \SplQueue<array{Process, Asset}> $running
     * @return \SplQueue<array{Process, Asset}>
     */
    private function startProcessesFormStack(Io $io, \SplQueue $running): \SplQueue
    {
        while (($running->count() < $this->maxParallel) && !$this->stack->isEmpty()) {
            $current = $this->stack->dequeue();
            [$process, $asset] = $current;

            $name = $asset->name();
            $command = $this->commands[$name] ?? '';
            $io->writeComment("Starting process of '{$name}' using: `{$command}`...");

            $process->start($this->outputHandler);
            $running->enqueue([$process, $asset]);
        }

        usleep($this->poll);

        return $running;
    }

    /**
     * @param Io $io
     * @param bool $stopOnFailure
     * @param \SplQueue<array{Process, Asset}> $running
     * @param \SplQueue<array{Process, Asset}> $successful
     * @param \SplQueue<array{Process, Asset}> $erroneous
     * @return array{
     *  \SplQueue<array{Process, Asset}>,
     *  \SplQueue<array{Process, Asset}>,
     *  \SplQueue<array{Process, Asset}>
     * }
     */
    private function checkRunningProcesses(
        Io $io,
        bool $stopOnFailure,
        \SplQueue $running,
        \SplQueue $successful,
        \SplQueue $erroneous
    ): array {

        /** @var \SplQueue<array{Process, Asset}> $stillRunning */
        $stillRunning = new \SplQueue();

        $stopAnyRunning = false;
        while (!$running->isEmpty()) {
            $current = $running->dequeue();
            [$process, $asset] = $current;
            $name = $asset->name();

            $isRunning = $process->isRunning();

            if ($isRunning && $stopAnyRunning) {
                $process->stop(0.2);
                continue;
            }

            if ($isRunning) {
                $stillRunning->enqueue([$process, $asset]);
                continue;
            }

            if (!$process->isSuccessful()) {
                $veryVerbose = $io->isVeryVerbose();
                $prefix = $veryVerbose ? '' : "\n";
                $io->writeError("{$prefix}Failed processing {$name}.");
                $veryVerbose and $this->writeProcessError($process, $io);
                $erroneous->enqueue([$process, $asset]);
                $stopAnyRunning or $stopAnyRunning = $stopOnFailure;
                continue;
            }

            $io->writeInfo("Processing of {$name} done successfully.");
            $successful->enqueue([$process, $asset]);
        }

        return [$stillRunning, $successful, $erroneous];
    }

    /**
     * @param Process $process
     * @param Io $io
     * @return void
     */
    private function writeProcessError(Process $process, Io $io): void
    {
        $lines = explode("\n", trim($process->getErrorOutput()));
        foreach ($lines as $line) {
            $cleanLine = trim($line);
            $cleanLine and $io->writeError("   {$cleanLine}");
        }
    }
}
