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
    private bool $newMethod;
    private float $timeout;

    /**
     * @return Factory
     */
    public static function new(): Factory
    {
        return new self();
    }

    /**
     */
    private function __construct()
    {
        $this->newMethod = method_exists(Process::class, 'fromShellCommandline');
        $this->timeout = 86400.0;
    }

    /**
     * @param string $command
     * @param string $cwd
     * @return Process
     */
    public function create(string $command, string $cwd): Process
    {
        /**
         * @psalm-suppress InvalidArgument
         * @noinspection PhpParamsInspection
         */
        return $this->newMethod
            ? Process::fromShellCommandline($command, $cwd, null, null, $this->timeout)
            : new Process($command, $cwd, null, null, $this->timeout);
    }
}
