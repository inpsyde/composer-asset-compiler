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
use Inpsyde\AssetsCompiler\Util\EnvResolver;
use Inpsyde\AssetsCompiler\Util\Io;

final class Commands
{
    public const YARN = 'yarn';
    public const NPM = 'npm';

    private const DEPENDENCIES = 'dependencies';
    private const DEPENDENCIES_INSTALL = 'install';
    private const DEPENDENCIES_UPDATE = 'update';
    private const DISCOVER = 'discover';
    private const SCRIPT = 'script';

    private const SUPPORTED_DEFAULTS = [
        self::YARN => [
            self::DEPENDENCIES => [
                self::DEPENDENCIES_INSTALL => 'yarn',
                self::DEPENDENCIES_UPDATE => 'yarn upgrade',
            ],
            self::SCRIPT => 'yarn %s',
            self::DISCOVER => 'yarn --version',
        ],
        self::NPM => [
            self::DEPENDENCIES => [
                self::DEPENDENCIES_INSTALL => 'npm install',
                self::DEPENDENCIES_UPDATE => 'npm update --no-save',
            ],
            self::SCRIPT => 'npm run %s',
            self::DISCOVER => 'npm --version',
        ],
    ];

    /**
     * @var list<string>|null
     */
    private static $tested = null;

    /**
     * @var array{update: null|string, install: null|string}
     */
    private $dependencies;

    /**
     * @var string|null
     */
    private $script;

    /**
     * @var array
     */
    private $defaultEnvironment;

    /**
     * @param ProcessExecutor $executor
     * @param string|null $workingDir
     * @param array $defaultEnvironment
     * @return list<string>
     */
    public static function test(ProcessExecutor $executor, ?string $workingDir = null): array
    {
        if (is_array(self::$tested)) {
            return self::$tested;
        }

        self::$tested = [];
        foreach (self::SUPPORTED_DEFAULTS as $name => $data) {
            $discover = $data[self::DISCOVER] ?? '';
            $out = null;
            if ($discover && ($executor->execute($discover, $out, $workingDir) === 0)) {
                self::$tested[] = $name;
            }
        }

        return self::$tested;
    }

    /**
     * @param string $manager
     * @param array $defaultEnvironment
     * @return Commands
     */
    public static function fromDefault(string $manager, array $defaultEnvironment = []): Commands
    {
        $manager = strtolower($manager);

        if (!array_key_exists($manager, self::SUPPORTED_DEFAULTS)) {
            return new self([], $defaultEnvironment);
        }

        return new self(self::SUPPORTED_DEFAULTS[$manager], $defaultEnvironment);
    }

    /**
     * @param ProcessExecutor $executor
     * @param string $workingDir
     * @param array $defaultEnvironment
     * @return Commands
     */
    public static function discover(
        ProcessExecutor $executor,
        string $workingDir,
        array $defaultEnvironment = []
    ): Commands {

        $tested = self::test($executor, $workingDir);
        if ($tested === []) {
            return new self([], $defaultEnvironment);
        }

        return static::fromDefault(reset($tested), $defaultEnvironment);
    }

    /**
     * @param array $config
     * @param array $defaultEnvironment
     * @return Commands
     */
    public static function new(array $config, array $defaultEnvironment = []): Commands
    {
        return new static($config, $defaultEnvironment);
    }

    /**
     * @param array $config
     * @param array $defaultEnvironment
     */
    private function __construct(array $config, array $defaultEnvironment = [])
    {
        $this->defaultEnvironment = $defaultEnvironment;

        $dependencies = $config[self::DEPENDENCIES] ?? null;

        $install = null;
        $update = null;

        if ($dependencies && is_array($dependencies)) {
            $install = $dependencies[self::DEPENDENCIES_INSTALL] ?? null;
            $update = $dependencies[self::DEPENDENCIES_UPDATE] ?? null;
        }

        /** @var string|null $install */
        /** @var string|null $update */

        $this->dependencies = [
            self::DEPENDENCIES_INSTALL => is_string($install) ? $install : null,
            self::DEPENDENCIES_UPDATE => is_string($update) ? $update : null,
        ];

        $script = $config[self::SCRIPT] ?? null;
        if ($script && is_string($script) && substr_count($script, '%s') === 1) {
            $this->script = $script;
        }
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        return !empty($this->dependencies[self::DEPENDENCIES_INSTALL]) && $this->scriptCmd('test');
    }

    /**
     * @param array $environment
     * @return Commands
     */
    public function withDefaultEnv(array $environment): Commands
    {
        return new self(
            [self::DEPENDENCIES => $this->dependencies, self::SCRIPT => $this->script],
            $environment
        );
    }

    /**
     * @param Io $io
     * @return string|null
     */
    public function installCmd(Io $io): ?string
    {
        return $this->maybeVerbose($this->dependencies[self::DEPENDENCIES_INSTALL], $io);
    }

    /**
     * @param Io $io
     * @return string|null
     */
    public function updateCmd(Io $io): ?string
    {
        return $this->maybeVerbose($this->dependencies[self::DEPENDENCIES_UPDATE], $io);
    }

    /**
     * @param string $command
     * @param array $env
     * @return string|null
     */
    public function scriptCmd(string $command, array $env = []): ?string
    {
        if (!$this->script) {
            return null;
        }

        $environment = array_merge(array_filter($this->defaultEnvironment), array_filter($env));
        $command = EnvResolver::replaceEnvVariables($command, $environment);

        // To pass arguments to scripts defined in package.json, npm requires `--` to be used,
        // whereas Yarn requires the arguments to be appended to script name.
        // For example, `npm run foo -- --bar=baz` is equivalent to `yarn foo --bar=baz`.
        // This is why if the command defined in "script" contains ` -- ` and we are using Yarn
        // then we remove `--`.
        $cmdParams = '';
        if (substr_count($command, ' -- ', 1) === 1) {
            $isYarn = stripos($this->script, 'yarn') !== false;
            [$commandNoArgs, $cmdParams] = explode(' -- ', $command, 2);
            $commandNoArgsClean = trim($commandNoArgs);
            if ($commandNoArgsClean) {
                $command = trim($commandNoArgsClean);
                $cmdParams = trim($cmdParams);
            }
            if ($cmdParams && !$isYarn) {
                $cmdParams = "-- {$cmdParams}";
            }
        }

        $resolved = trim(sprintf($this->script, $command));
        $cmdParams and $resolved .= " {$cmdParams}";

        return $resolved;
    }

    /**
     * @param ProcessExecutor $executor
     * @param string|null $cwd
     * @return bool
     */
    public function isExecutable(ProcessExecutor $executor, ?string $cwd): bool
    {
        $isYarn = stripos($this->script ?? '', 'yarn') !== false;
        $isNpm = stripos($this->script ?? '', 'npm') !== false;
        if (!$isYarn && !$isNpm) {
            return false;
        }

        $tested = self::test($executor, $cwd);

        return in_array($isYarn ? self::YARN : self::NPM, $tested, true);
    }

    /**
     * @param string|null $cmd
     * @param Io $io
     * @return string|null
     */
    private function maybeVerbose(?string $cmd, Io $io): ?string
    {
        if (!$cmd) {
            return $cmd;
        }

        $isYarn = stripos($cmd, 'yarn') !== false;
        $isNpm = !$isYarn && stripos($cmd, 'npm') !== false;

        if (!$isYarn && !$isNpm) {
            return $cmd;
        }

        return $isYarn
            ? $this->maybeVerboseYarn($cmd, $io)
            : $this->maybeVerboseNpm($cmd, $io);
    }

    /**
     * @param string $cmd
     * @param Io $io
     * @return string
     */
    private function maybeVerboseYarn(string $cmd, Io $io): string
    {
        if (!$io->isInteractive() && (stripos($cmd, '-interactive') === false)) {
            $cmd .= ' --non-interactive';
        }

        if ((stripos($cmd, '-verbose') !== false) || (stripos($cmd, '-silent') !== false)) {
            return $cmd;
        }

        if ($io->isQuiet()) {
            return "{$cmd} --silent";
        }

        return $io->isVeryVeryVerbose() ? "{$cmd} --verbose" : $cmd;
    }

    /**
     * @param string $cmd
     * @param Io $io
     * @return string
     */
    private function maybeVerboseNpm(string $cmd, Io $io): string
    {
        if (
            (stripos($cmd, '-d') !== false)
            || (stripos($cmd, '-s') !== false)
            || (stripos($cmd, '-loglevel') !== false)
            || (stripos($cmd, '-silent') !== false)
            || (stripos($cmd, '-quiet') !== false)
        ) {
            return $cmd;
        }

        switch (true) {
            case $io->isQuiet():
                return "{$cmd} --silent";
            case $io->isVeryVeryVerbose():
                return "{$cmd} -ddd";
            case $io->isVeryVerbose():
                return "{$cmd} -dd";
        }

        return $io->isVerbose() ? "{$cmd} -d" : $cmd;
    }
}
