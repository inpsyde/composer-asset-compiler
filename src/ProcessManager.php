<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler;

use Composer\Util\Platform;
use Symfony\Component\Process\Process;

class ProcessManager
{

    /**
     * @var callable
     */
    private $outputHandler;

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
     * @param callable $outputHandler
     */
    public function __construct(
        callable $outputHandler,
        int $timeoutLimit = 600,
        int $maxParallel = 4,
        int $poll = 100000
    ) {

        $this->outputHandler = $outputHandler;
        $this->timeoutLimit = $timeoutLimit > 10 ? $timeoutLimit : 1000;
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
        $process = $this->factoryProcessForPackage($package, $packageCommand);
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
        if (!$this->total) {
            return ProcessResults::empty();
        }

        $this->executionStarted = (float)microtime(true);

        /**
         * @var \SplQueue $successful
         * @var \SplQueue $erroneous
         */
        [$timedOut, $successful, $erroneous] = $this->executeProcesses($io, $stopOnFailure);

        $this->resetStatus();

        return $timedOut
            ? ProcessResults::timeout($this->total, $successful, $erroneous)
            : ProcessResults::new($this->total, $successful, $erroneous);
    }

    /**
     * @return void
     */
    private function resetStatus(): void
    {
        $this->stack = new \SplQueue();
        $this->total = 0;
        $this->executionStarted = 0.0;
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param bool $stopOnFailure
     * @param \SplQueue|null $running
     * @param \SplQueue|null $successful
     * @param \SplQueue|null $erroneous
     * @return array{0:boll, 1:\SplQueue, 2:\SplQueue, 2:\SplQueue}
     */
    private function executeProcesses(
        Io $io,
        bool $stopOnFailure,
        ?\SplQueue $running = null,
        ?\SplQueue $successful = null,
        ?\SplQueue $erroneous = null
    ): array {

        if (
            $this->timeoutLimit > 10 // Timeout less than 10 seconds is ignored
            && ((microtime(true) - $this->executionStarted) > $this->timeoutLimit)
        ) {
            return [true, $running, $successful, $erroneous];
        }

        $running = $this->startProcessesFormStack($io, $running ?? new \SplQueue());

        [$stillRunning, $successful, $erroneous] = $this->checkRunningProcesses(
            $io,
            $running,
            $successful ?? new \SplQueue(),
            $erroneous ?? new \SplQueue()
        );

        if (!$erroneous->isEmpty() && $stopOnFailure) {
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
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param \SplQueue $running
     * @return \SplQueue
     */
    private function startProcessesFormStack(Io $io, \SplQueue $running): \SplQueue
    {
        while (($running->count() < $this->maxParallel) && !$this->stack->isEmpty()) {
            /**
             * @var Process $process
             * @var \Inpsyde\AssetsCompiler\Package $package
             */
            [$process, $package] = $this->stack->dequeue();

            $name = $package->name();
            $io->writeVerboseComment(" - Starting process of '{$name}'");

            $process->start($this->outputHandler);
            $running->enqueue([$process, $package]);
        }

        usleep($this->poll);

        return $running;
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param \SplQueue $running
     * @param \SplQueue $successful
     * @param \SplQueue $erroneous
     * @return @return array{0:\SplQueue, 1:\SplQueue, 2:\SplQueue}
     */
    private function checkRunningProcesses(
        Io $io,
        \SplQueue $running,
        \SplQueue $successful,
        \SplQueue $erroneous
    ): array {

        $stillRunning = new \SplQueue();

        while (!$running->isEmpty()) {
            /**
             * @var Process $process
             * @var \Inpsyde\AssetsCompiler\Package $package
             */
            [$process, $package] = $running->dequeue();
            $name = $package->name();

            if ($process->isRunning()) {
                $stillRunning->enqueue([$process, $package]);
                continue;
            }

            if (!$process->isSuccessful()) {
                $io->writeError(" - Failed processing {$name}.");
                $erroneous->enqueue([$process, $package]);
                continue;
            }

            $io->writeVerboseComment(" - Processing of {$name} done successfully.");
            $successful->enqueue([$process, $package]);
        }

        return [$stillRunning, $successful, $erroneous];
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Package $package
     * @param string $command
     * @return \Symfony\Component\Process\Process
     */
    private function factoryProcessForPackage(Package $package, string $command): Process
    {
        $cwd = $package->path();

        if ($cwd === null && Platform::isWindows() && stripos($command, 'git ') !== false) {
            $cwdRaw = getcwd();
            $cwdRaw and $cwd = (realpath($cwdRaw) ?: null);
        }

        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            return Process::fromShellCommandline($command, $cwd, null, null, (float)PHP_INT_MAX);
        }

        /** @noinspection PhpParamsInspection */
        return new Process($command, $cwd, null, null, (float)PHP_INT_MAX);
    }
}
