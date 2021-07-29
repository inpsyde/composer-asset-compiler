<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Commands;

use Composer\Json\JsonFile;
use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Util\Io;
use Inpsyde\AssetsCompiler\Asset\RootConfig;

class Finder
{

    /**
     * @var ProcessExecutor
     */
    private $executor;

    /**
     * @var EnvResolver
     */
    private $envResolver;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Io
     */
    private $io;

    /**
     * @var array
     */
    private $defaultEnv;

    /**
     * @var Commands|null
     */
    private $discovered;

    /**
     * @param ProcessExecutor $executor
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param Io $io
     * @param array $defaultEnv
     * @return Finder
     */
    public static function new(
        ProcessExecutor $executor,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        Io $io,
        array $defaultEnv
    ): Finder {

        return new self($executor, $envResolver, $filesystem, $io, $defaultEnv);
    }

    /**
     * @param ProcessExecutor $executor
     * @param EnvResolver $envResolver
     * @param Filesystem $filesystem
     * @param Io $io
     * @param array $defaultEnv
     */
    private function __construct(
        ProcessExecutor $executor,
        EnvResolver $envResolver,
        Filesystem $filesystem,
        Io $io,
        array $defaultEnv
    ) {

        $this->executor = $executor;
        $this->envResolver = $envResolver;
        $this->filesystem = $filesystem;
        $this->io = $io;
        $this->defaultEnv = $defaultEnv;
    }

    /**
     * @param RootConfig $config
     * @return Commands
     */
    public function find(RootConfig $config): Commands
    {
        $rootDir = $config->rootDir();
        [$data, $byDefault] = $config->commands();
        $commands = null;
        $discover = $data === null;
        $checkValid = true;

        switch (true) {
            case ($byDefault && is_string($data)):
                $commands = Commands::fromDefault($data);
                break;
            case ($data && is_array($data)):
                $commands = Commands::new($data);
                break;
            case file_exists("{$rootDir}/yarn.lock"):
                $commands = Commands::fromDefault(Commands::YARN);
                $discover = false;
                $checkValid = false;
                break;
            case file_exists("{$rootDir}/package-lock.json"):
            case file_exists("{$rootDir}/npm-shrinkwrap.json"):
                $commands = Commands::fromDefault(Commands::NPM);
                $discover = false;
                $checkValid = false;
                break;
            case ($discover):
                $commands = $this->discovered ?: Commands::discover($this->executor, $rootDir);
                break;
        }

        if ($commands && !$this->checkIsValid($commands, $rootDir, $checkValid, !$discover)) {
            $commands = null;
        }

        if (!$commands && !$discover) {
            $commands = $this->discovered ?: Commands::discover($this->executor, $rootDir);
            $discover = true;
        }

        ($discover && !$this->discovered) and $this->discovered = $commands;

        $this->assertValid($commands);

        return $commands->withDefaultEnv($this->defaultEnv);
    }

    /**
     * @param Asset $asset
     * @return Commands
     */
    public function findForAsset(Asset $asset): Commands
    {
        $path = $asset->path();
        if (!$path) {
            return Commands::new([], []);
        }
        $composerFile = "{$path}/composer.json";

        $file = new JsonFile($composerFile, null, $this->io->composerIo());
        $data = $file->read();
        if (!$data || !is_array($data)) {
            throw new \Exception("Could not parse {$composerFile} as valid composer.json.");
        }

        $version = $asset->version() ?? 'dev-master';
        $root = new RootPackage($asset->name(), $version, $version);
        $root->setExtra((array)($data['extra'] ?? []));

        return $this->find(RootConfig::new($root, $this->envResolver, $this->filesystem, $path));
    }

    /**
     * @param Commands $commands
     * @param string $cwd
     * @param bool $checkValid
     * @param bool $checkExecutable
     * @return bool
     */
    private function checkIsValid(
        Commands $commands,
        string $cwd,
        bool $checkValid,
        bool $checkExecutable
    ): bool {

        $valid = $checkValid ? $commands->isValid() : true;

        if ($valid && $checkExecutable) {
            $valid = $commands->isExecutable($this->executor, $cwd);
        }

        if (!$valid) {
            $this->io->writeError('Commands config is invalid, will try now to auto-discover.');
        }

        return $valid;
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
