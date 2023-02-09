<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Commands;

use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Asset\RootConfig;

class Finder
{
    /**
     * @var RootConfig
     */
    private $config;

    /**
     * @var ProcessExecutor
     */
    private $executor;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var Commands|null
     */
    private $commands;

    /**
     * @param RootConfig $config
     * @param ProcessExecutor $executor
     * @param Io $io
     * @return Finder
     */
    public static function new(
        RootConfig $config,
        ProcessExecutor $executor,
        Io $io
    ): Finder {

        return new self($config, $executor, $io);
    }

    /**
     * @param RootConfig $config
     * @param ProcessExecutor $executor
     * @param Io $io
     */
    private function __construct(RootConfig $config, ProcessExecutor $executor, Io $io)
    {
        $this->config = $config;
        $this->executor = $executor;
        $this->io = $io;
    }

    /**
     * @param string $workingDir
     * @return Commands
     */
    public function find(string $workingDir): Commands
    {
        if ($this->commands) {
            return $this->commands;
        }

        [$config, $byDefault] = $this->config->commands();
        $defaultEnv = $this->config->defaultEnv();
        $commands = null;
        $discover = $config === null;

        switch (true) {
            case ($discover):
                $commands = Commands::discover($this->executor, $workingDir, $defaultEnv);
                break;
            case ($byDefault && is_string($config)):
                $commands = Commands::fromDefault($config, $defaultEnv);
                break;
            case ($config && is_array($config)):
                $commands = Commands::new($config, $defaultEnv);
                break;
        }

        if ((!$commands || !$commands->isValid()) && !$discover) {
            $this->io->writeError('Commands config is invalid, will try now to auto-discover.');
            $commands = Commands::discover($this->executor, $workingDir);
        }

        $this->assertValid($commands);
        $this->commands = $commands;

        return $commands;
    }

    /**
     * @param Commands|null $commands
     * @return void
     */
    public function assertValid(?Commands $commands): void
    {
        if (!$commands || !$commands->isValid()) {
            $this->io->writeError(
                ' Could not found a package manager.',
                ' Make sure either Yarn or npm are installed.'
            );

            throw new \Exception('Package manager not found.');
        }
    }
}
