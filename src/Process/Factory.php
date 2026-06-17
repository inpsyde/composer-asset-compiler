<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Process;

use Symfony\Component\Process\Process;

class Factory
{
    /**
     * @var bool
     */
    private $newMethod;

    /**
     * @var float
     */
    private $timeout;

    /**
     * @var callable|null
     */
    private $factory;

    /**
     * @param callable(string, ?string=):?Process|null $factory
     * @return Factory
     */
    public static function new(?callable $factory = null): Factory
    {
        return new self($factory);
    }

    /**
     * @param callable(string, ?string=):?Process $factory
     */
    private function __construct(?callable $factory = null)
    {
        $this->newMethod = !$factory && method_exists(Process::class, 'fromShellCommandline');
        $this->timeout = 86400.0;
        $this->factory = $factory;
    }

    /**
     * @param string $command
     * @param string $cwd
     * @return Process
     */
    public function create(string $command, string $cwd): Process
    {
        if ($this->factory) {
            $process = ($this->factory)($command, $cwd);
            if (!$process instanceof Process) {
                throw new \Exception('Could not factory a process from given factory.');
            }

            return $process;
        }

        if ($this->newMethod) {
            $process = Process::fromShellCommandline($command, $cwd, null, null, $this->timeout);

            return $process;
        }

        /**
         * @psalm-suppress InvalidArgument
         * @noinspection PhpParamsInspection
         */
        return new Process($command, $cwd, null, null, $this->timeout);
    }
}
