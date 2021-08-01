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
    private const DEPS_INSTALL = 'install';
    private const DEPS_UPDATE = 'update';
    private const DISCOVER = 'discover';
    private const CLEAN_CACHE = 'clean-cache';
    private const SCRIPT = 'script';
    private const MANAGER = 'name';

    private const SUPPORTED_DEFAULTS = [
        self::YARN => [
            self::DEPENDENCIES => [
                self::DEPS_INSTALL => 'yarn',
                self::DEPS_UPDATE => 'yarn upgrade',
            ],
            self::SCRIPT => 'yarn %s',
            self::DISCOVER => 'yarn --version',
            self::CLEAN_CACHE => 'yarn cache clean',
        ],
        self::NPM => [
            self::DEPENDENCIES => [
                self::DEPS_INSTALL => 'npm install',
                self::DEPS_UPDATE => 'npm update --no-save',
            ],
            self::SCRIPT => 'npm run %s',
            self::DISCOVER => 'npm --version',
            self::CLEAN_CACHE => 'npm cache clear --force',
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
     * @var string
     */
    private $cacheClean;

    /**
     * @var array
     */
    private $defaultEnvironment;

    /**
     * @var string|null
     */
    private $name;

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
        $this->reset();
        $this->defaultEnvironment = $defaultEnvironment;

        $dependencies = $this->parseDependencies($config);
        if (empty($dependencies[self::DEPS_INSTALL])) {
            $this->reset();

            return;
        }
        $this->dependencies = $dependencies;

        $script = $config[self::SCRIPT] ?? null;
        if ($script && is_string($script) && substr_count($script, '%s') === 1) {
            $this->script = $script;
        }

        if (!$this->isValid()) {
            $this->reset();

            return;
        }

        $manager = $config[self::MANAGER] ?? null;
        $name = ($manager && is_string($manager)) ? strtolower(trim($manager)) : null;
        in_array($name, [self::YARN, self::NPM], true) or $name = null;
        $this->name = $name;

        $isYarn = $this->isYarn();
        if (!$isYarn && !$this->isNpm()) {
            $this->reset();

            return;
        }

        $clean = $config[self::CLEAN_CACHE] ?? null;
        $defaults = self::SUPPORTED_DEFAULTS[$isYarn ? self::YARN : self::NPM];
        $this->cacheClean = ($clean && is_string($clean)) ? $clean : $defaults[self::CLEAN_CACHE];
    }

    /**
     * @return bool
     *
     * @psalm-assert-if-true non-empty-string $this->script
     */
    public function isValid(): bool
    {
        return !empty($this->dependencies[self::DEPS_INSTALL]) && $this->scriptCmd('test');
    }

    /**
     * @return bool
     */
    public function isNpm(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($this->name) {
            return $this->name === self::NPM;
        }

        if ($this->script && stripos($this->script, 'npm') !== false) {
            $this->name = self::NPM;

            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isYarn(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($this->name) {
            return $this->name === self::YARN;
        }

        if ($this->script && stripos($this->script, 'yarn') !== false) {
            $this->name = self::YARN;

            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    public function name(): string
    {
        if (!$this->isValid()) {
            return 'invalid';
        }

        return $this->isYarn() ? 'yarn' : 'npm';
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
        return $this->maybeVerbose($this->dependencies[self::DEPS_INSTALL], $io);
    }

    /**
     * @param Io $io
     * @return string|null
     */
    public function updateCmd(Io $io): ?string
    {
        return $this->maybeVerbose($this->dependencies[self::DEPS_UPDATE], $io);
    }

    /**
     * @return string
     */
    public function cleanCacheCmd(): string
    {
        return $this->cacheClean;
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
            [$commandNoArgs, $cmdParams] = explode(' -- ', $command, 2);
            $commandNoArgsClean = trim($commandNoArgs);
            if ($commandNoArgsClean) {
                $command = trim($commandNoArgsClean);
                $cmdParams = trim($cmdParams);
            }
            if ($cmdParams && !$this->isYarn()) {
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
        $isYarn = $this->isYarn();
        if (!$isYarn && !$this->isNpm()) {
            return false;
        }

        $tested = self::test($executor, $cwd);

        return in_array($isYarn ? self::YARN : self::NPM, $tested, true);
    }

    /**
     * @return void
     */
    private function reset(): void
    {
        $this->script = null;
        $this->dependencies = [self::DEPS_INSTALL => null, self::DEPS_UPDATE => null];
        $this->name = null;
        $this->cacheClean = '';
        $this->defaultEnvironment = [];
    }

    /**
     * @param array $config
     * @return array{update: null|string, install: null|string}
     */
    private function parseDependencies(array $config): array
    {
        $dependencies = $config[self::DEPENDENCIES] ?? null;
        $install = null;
        $update = null;
        if ($dependencies && is_array($dependencies)) {
            $install = $dependencies[self::DEPS_INSTALL] ?? null;
            $update = $dependencies[self::DEPS_UPDATE] ?? null;
            (($install !== '') && is_string($install)) or $install = null;
            (($update !== '') && is_string($update)) or $update = null;
        }

        /** @var non-empty-string|null $install */
        /** @var non-empty-string|null $update */

        if (($install === null) && $update) {
            $install = $update;
        } elseif (($update === null) && $install) {
            $update = $install;
        }

        return [self::DEPS_INSTALL => $install, self::DEPS_UPDATE => $update];
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

        $isYarn = $this->isYarn();
        if (!$isYarn && !$this->isNpm()) {
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
