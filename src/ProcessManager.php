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
     * @var array<string, string>
     */
    private $commands;

    /**
     * @param callable $outputHandler
     */
    public function __construct(
        callable $outputHandler,
        int $timeoutLimit = 600, # seconds, default: 10 minutes
        int $maxParallel = 4,
        int $poll = 100000       # milliseconds, default: 1/10 of second
    ) {

        $this->outputHandler = $outputHandler;
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
        $process = $this->factoryProcessForPackage($package, $packageCommand);
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
        [$timedOut, $successful, $erroneous] = $this->executeProcesses($io, $stopOnFailure);

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

        $running = $this->startProcessesFormStack($io, $running ?? new \SplQueue());

        [$stillRunning, $successful, $erroneous] = $this->checkRunningProcesses(
            $io,
            $stopOnFailure,
            $running,
            $successful ?? new \SplQueue(),
            $erroneous ?? new \SplQueue()
        );

        if ($this->checkTimedOut()) {
            return [true, $successful, $erroneous, $running];
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
        return (microtime(true) - $this->executionStarted) > $this->timeoutLimit;
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
            $command = $this->commands[$name] ?? '';
            $io->writeVerboseComment(" - Starting process of '{$name}'");
            $command and $io->writeVerboseComment("   $ {$command}");

            $process->start($this->outputHandler);
            $running->enqueue([$process, $package]);
        }

        usleep($this->poll);

        return $running;
    }

    /**
     * @param \Inpsyde\AssetsCompiler\Io $io
     * @param bool $stopOnFailure ,
     * @param \SplQueue $running
     * @param \SplQueue $successful
     * @param \SplQueue $erroneous
     * @return @return array{0:\SplQueue, 1:\SplQueue, 2:\SplQueue}
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
            /**
             * @var Process $process
             * @var \Inpsyde\AssetsCompiler\Package $package
             */
            [$process, $package] = $running->dequeue();
            $name = $package->name();

            $isRunning = $process->isRunning();

            if ($isRunning && $stopAnyRunning) {
                $process->stop();
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
        $lines = explode("\n", trim($process->getErrorOutput()));
        foreach ($lines as $line) {
            $cleanLine = trim($line);
            $cleanLine and $io->writeError("   {$cleanLine}");
        }
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
