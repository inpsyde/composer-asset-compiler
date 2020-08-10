<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Symfony\Component\Process\Process;

class ProcessManager
{

    /**
     * @var callable
     */
    private $outputHandler;

    /**
     * @var \Inpsyde\AssetsCompiler\ProcessFactory
     */
    private $processFactory;

    /**
     * @var int
     */
    private $timeoutLimit;

    /**
     * @var int
     */
    private $maxParallel;

    /**
     * @var int
     */
    private $poll;

    /**
     * @var \SplQueue
     */
    private $stack;

    /**
     * @var int
     */
    private $total = 0;

    /**
     * @var float
     */
    private $executionStarted;

    /**
     * @var array<string, string>
     */
    private $commands;

    /**
     * @param callable $outputHandler
     * @param \Inpsyde\AssetsCompiler\ProcessFactory $processFactory
     * @param int $timeoutLimit
     * @param int $maxParallel
     * @param int $poll
     */
    public function __construct(
        callable $outputHandler,
        ProcessFactory $processFactory,
        int $timeoutLimit = 600, # seconds, default: 10 minutes
        int $maxParallel = 4,
        int $poll = 100000       # milliseconds, default: 1/10 of second
    ) {

        $this->outputHandler = $outputHandler;
        $this->processFactory = $processFactory;
        $this->timeoutLimit = $timeoutLimit >= 10 ? $timeoutLimit : 1000;
        $this->maxParallel = $maxParallel >= 1 ? $maxParallel : 4;
        $this->poll = $poll >= 10000 ? $poll : 100000;

        $this->resetStatus();
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Package $package
     * @param string $packageCommand
     * @param string|null $cwd
     * @return ProcessManager
     */
    public function pushPackageToProcess(Package $package, string $packageCommand): ProcessManager
    {
        $process = $this->processFactory->create($packageCommand, (string)$package->path());
        $this->commands[$package->name()] = $packageCommand;
        $this->stack->enqueue([$process, $package]);
        $this->total++;

        return $this;
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @return \Inpsyde\AssetsCompiler\ProcessResults
     */
    public function execute(Io $io, bool $stopOnFailure): ProcessResults
    {
        if ($this->total <= 0) {
            return ProcessResults::empty();
        }

        $this->executionStarted = (float)microtime(true);

        /**
         * @var \SplQueue $successful
         * @var \SplQueue $erroneous
         */
        [$timedOut, $successful, $erroneous] = $this->executeProcesses(
            $io,
            $stopOnFailure,
            new \SplQueue(),
            new \SplQueue(),
            new \SplQueue()
        );

        $results = $timedOut
            ? ProcessResults::timeout($this->total, $successful, $erroneous)
            : ProcessResults::new($this->total, $successful, $erroneous);

        $this->resetStatus();

        return $results;
    }

    /**
     * @return void
     */
    private function resetStatus(): void
    {
        $this->stack = new \SplQueue();
        $this->total = 0;
        $this->executionStarted = 0.0;
        $this->commands = [];
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param bool $stopOnFailure
     * @param \SplQueue $running
     * @param \SplQueue $successful
     * @param \SplQueue $erroneous
     * @return array{0:bool, 1:\SplQueue, 2:\SplQueue, 2:\SplQueue}
     */
    private function executeProcesses(
        Io $io,
        bool $stopOnFailure,
        \SplQueue $running,
        \SplQueue $successful,
        \SplQueue $erroneous
    ): array {

        $running = $this->startProcessesFormStack($io, $running);

        /**
         * @var \SplQueue $stillRunning
         * @var \SplQueue $successful
         * @var \SplQueue $erroneous
         */
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
        return ((float)microtime(true) - $this->executionStarted) > $this->timeoutLimit;
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param \SplQueue $running
     * @return \SplQueue
     */
    private function startProcessesFormStack(Io $io, \SplQueue $running): \SplQueue
    {
        while (($running->count() < $this->maxParallel) && !$this->stack->isEmpty()) {
            /** @var array{Process, Package} $current */
            $current = $this->stack->dequeue();
            [$process, $package] = $current;

            $name = $package->name();
            $command = $this->commands[$name] ?? '';
            $io->writeVerboseComment(" - Starting process of '{$name}' using: `{$command}`.");

            $process->start($this->outputHandler);
            $running->enqueue([$process, $package]);
        }

        usleep($this->poll);

        return $running;
    }

    /**
     * @param Io $io
     * @param bool $stopOnFailure ,
     * @param \SplQueue $running
     * @param \SplQueue $successful
     * @param \SplQueue $erroneous
     * @return array{0:\SplQueue, 1:\SplQueue, 2:\SplQueue}
     */
    private function checkRunningProcesses(
        Io $io,
        bool $stopOnFailure,
        \SplQueue $running,
        \SplQueue $successful,
        \SplQueue $erroneous
    ): array {

        $stillRunning = new \SplQueue();

        $stopAnyRunning = false;
        while (!$running->isEmpty()) {
            /** @var array{Process, Package} $current */
            $current = $running->dequeue();
            [$process, $package] = $current;
            $name = $package->name();

            $isRunning = $process->isRunning();

            if ($isRunning && $stopAnyRunning) {
                $process->stop(0.2);
                continue;
            }

            if ($isRunning) {
                $stillRunning->enqueue([$process, $package]);
                continue;
            }

            if (!$process->isSuccessful()) {
                $veryVerbose = $io->isVeryVerbose();
                $prefix = $veryVerbose ? '' : "\n";
                $io->writeError("{$prefix} - Failed processing {$name}.");
                $veryVerbose or $this->writeProcessError($process, $io);
                $erroneous->enqueue([$process, $package]);
                $stopAnyRunning or $stopAnyRunning = $stopOnFailure;
                continue;
            }

            $io->writeVerboseInfo(" - Processing of {$name} done successfully.");
            $successful->enqueue([$process, $package]);
        }

        return [$stillRunning, $successful, $erroneous];
    }

    /**
     * @return void
     */
    private function writeProcessError(Process $process, Io $io): void
    {
        $lines = explode("\n", trim((string)$process->getErrorOutput()));
        foreach ($lines as $line) {
            $cleanLine = trim($line);
            $cleanLine and $io->writeError("   {$cleanLine}");
        }
    }
}
