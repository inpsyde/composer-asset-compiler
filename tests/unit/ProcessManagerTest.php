<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests\Unit;

use Inpsyde\AssetsCompiler\EnvResolver;
use Inpsyde\AssetsCompiler\Package;
use Inpsyde\AssetsCompiler\PackageConfig;
use Inpsyde\AssetsCompiler\ProcessFactory;
use Inpsyde\AssetsCompiler\ProcessManager;
use Inpsyde\AssetsCompiler\Tests\TestCase;
use Symfony\Component\Process\Process;

class ProcessManagerTest extends TestCase
{
    public function testWithNoProcesses()
    {
        $handler = static function () {
        };

        $manager = new ProcessManager($handler, new ProcessFactory());
        $result = $manager->execute($this->factoryIo(), false);

        static::assertTrue($result->isEmpty());
        static::assertFalse($result->isSuccessful());
        static::assertFalse($result->hasSuccesses());
        static::assertFalse($result->hasErrors());
        static::assertSame(0, $result->notExecutedCount());
    }

    public function testTwoBatches()
    {
        $handler = static function () {
        };

        /** @bound */
        $setter = function (Package $package, string $packageCommand, Process $process) {
            /** @noinspection PhpUndefinedFieldInspection */
            $this->commands[$package->name()] = $packageCommand;
            /** @noinspection PhpUndefinedFieldInspection */
            $this->stack->enqueue([$process, $package]);
            /** @noinspection PhpUndefinedFieldInspection */
            $this->total++;
        };

        $manager = new ProcessManager($handler, new ProcessFactory(), 100, 7);
        $bound = $setter->bindTo($manager, ProcessManager::class);

        $ok = true;
        foreach (range('A', 'L') as $name) {
            $process = $this->factoryProcess(0.1, $ok);
            $bound($this->factoryPackage($name), "run --{$name}", $process);
            $ok = !$ok;
        }

        $io = $this->factoryIo();

        $results = $manager->execute($io, false);

        static::assertFalse($results->timedOut());
        static::assertFalse($results->isEmpty());
        static::assertFalse($results->isSuccessful());
        static::assertSame(0, $results->notExecutedCount());
        static::assertTrue($results->hasSuccesses());
        static::assertTrue($results->hasErrors());
        static::assertSame(6, $results->successes()->count());
        static::assertSame(6, $results->errors()->count());
    }

    public function testStopOnFailure()
    {
        $handler = static function () {
        };

        /** @bound */
        $setter = function (Package $package, string $packageCommand, Process $process) {
            /** @noinspection PhpUndefinedFieldInspection */
            $this->commands[$package->name()] = $packageCommand;
            /** @noinspection PhpUndefinedFieldInspection */
            $this->stack->enqueue([$process, $package]);
            /** @noinspection PhpUndefinedFieldInspection */
            $this->total++;
        };

        $manager = new ProcessManager($handler, new ProcessFactory(), 100, 4);
        $bound = $setter->bindTo($manager, ProcessManager::class);

        $ok = false;
        foreach (range('A', 'L') as $name) {
            $process = $this->factoryProcess(0.1, $ok);
            $bound($this->factoryPackage($name), "run --{$name}", $process);
            $ok = !$ok;
        }

        $io = $this->factoryIo();

        $results = $manager->execute($io, true);

        static::assertFalse($results->timedOut());
        static::assertFalse($results->isEmpty());
        static::assertFalse($results->isSuccessful());
        static::assertSame(8, $results->notExecutedCount());
        static::assertTrue($results->hasSuccesses());
        static::assertTrue($results->hasErrors());
        static::assertSame(2, $results->successes()->count());
        static::assertSame(2, $results->errors()->count());
    }

    public function testTimeout()
    {
        $handler = static function () {
        };

        /** @bound */
        $setter = function (Package $package, string $packageCommand, Process $process) {
            /** @noinspection PhpUndefinedFieldInspection */
            $this->commands[$package->name()] = $packageCommand;
            /** @noinspection PhpUndefinedFieldInspection */
            $this->stack->enqueue([$process, $package]);
            /** @noinspection PhpUndefinedFieldInspection */
            $this->total++;
            /** @noinspection PhpUndefinedFieldInspection */
            $this->timeoutLimit = 1;
        };

        $manager = new ProcessManager($handler, new ProcessFactory(), 600, 4, 1000000);
        $bound = $setter->bindTo($manager, ProcessManager::class);

        $duration = 0.001;
        $ok = true;
        foreach (range('A', 'E') as $name) {
            $process = $this->factoryProcess($duration, $ok);
            if ($duration === 0.001) {
                $duration = 0.002;
                $ok = false;
            } elseif ($duration === 0.002) {
                $duration = 10;
            }

            $bound($this->factoryPackage($name), "run --{$name}", $process);
        }

        $io = $this->factoryIo();

        $results = $manager->execute($io, false);

        static::assertTrue($results->timedOut());
        static::assertFalse($results->isEmpty());
        static::assertFalse($results->isSuccessful());
        static::assertSame(3, $results->notExecutedCount());
        static::assertTrue($results->hasSuccesses());
        static::assertTrue($results->hasErrors());
        static::assertSame(1, $results->errors()->count());
        static::assertSame(1, $results->successes()->count());
    }

    /** @noinspection PhpParamsInspection */
    private function factoryProcess(float $duration, bool $success): Process
    {
        $process = \Mockery::mock(Process::class);

        $started = null;

        $process->shouldReceive('start')
            ->zeroOrMoreTimes()
            ->with(\Mockery::type(\Closure::class))
            ->andReturnUsing(
                static function () use (&$started) {
                    $started = microtime(true);
                }
            );

        $process->shouldReceive('stop');

        $process->shouldReceive('isRunning')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                static function () use (&$started, $duration): bool {
                    return $started && ((microtime(true) - $started) < $duration);
                }
            );

        $process->shouldReceive('isSuccessful')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                static function () use ($success, &$started): bool {
                    if ($started === null) {
                        throw new \Exception('isSuccessful should not be called without starting.');
                    }
                    return $success;
                }
            );

        $process->shouldReceive('getErrorOutput')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                static function () use ($success, &$started): ?string {
                    if ($started === null) {
                        throw new \Exception('getErrorOutput must not be called without starting.');
                    }

                    if ($success) {
                        throw new \Exception('getErrorOutput must not be called on success.');
                    }

                    return $success ? null : 'Error!';
                }
            );

        return $process;
    }

    /**
     * @param string $name
     * @return Package
     */
    private function factoryPackage(string $name): Package
    {
        $resolver = new EnvResolver('', false);
        $config = PackageConfig::forRawPackageData(['dependencies' => 'install'], $resolver);

        return Package::new($name, $config, __DIR__);
    }
}
