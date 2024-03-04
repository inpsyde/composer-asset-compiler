<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\PackageManager;

use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Util\Env;
use Inpsyde\AssetsCompiler\Util\Io;

final class PackageManager
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

    /** @var list<string>|null */
    private static array|null $tested = null;

    /** @var array{update: null|string, install: null|string} */
    private array $dependencies;
    private string|null $script;
    private string $cacheClean = '';
    private string|null $name = null;

    /**
     * @param ProcessExecutor $executor
     * @param string|null $workingDir
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
     * @return PackageManager
     */
    public static function fromDefault(string $manager): PackageManager
    {
        $manager = strtolower($manager);

        if (!array_key_exists($manager, self::SUPPORTED_DEFAULTS)) {
            return new self([]);
        }

        return new self(self::SUPPORTED_DEFAULTS[$manager]);
    }

    /**
     * @param ProcessExecutor $executor
     * @param string $workingDir
     * @return PackageManager
     */
    public static function discover(
        ProcessExecutor $executor,
        string $workingDir
    ): PackageManager {

        $tested = self::test($executor, $workingDir);
        if ($tested === []) {
            return new self([]);
        }

        return static::fromDefault(reset($tested));
    }

    /**
     * @param array $config
     * @return PackageManager
     */
    public static function new(array $config): PackageManager
    {
        return new self($config);
    }

    /**
     * @param array $config
     */
    private function __construct(array $config)
    {
        $this->reset();
        $this->dependencies = $this->parseDependencies($config);
        $this->script = $this->parseScript($config);

        if (!$this->isValid()) {
            $this->reset();

            return;
        }

        $manager = $config[self::MANAGER] ?? null;
        $name = is_string($manager) ? strtolower(trim($manager)) : null;
        in_array($name, [self::YARN, self::NPM], true) or $name = null;
        $this->name = $name;

        $isYarn = $this->isYarn();
        if (!$isYarn && !$this->isNpm()) {
            $this->reset();

            return;
        }

        $clean = $config[self::CLEAN_CACHE] ?? null;
        $defaults = self::SUPPORTED_DEFAULTS[$isYarn ? self::YARN : self::NPM];
        $this->cacheClean = (($clean !== '') && is_string($clean))
            ? $clean
            : $defaults[self::CLEAN_CACHE];
    }

    /**
     * @return bool
     *
     * @psalm-assert-if-true non-empty-string $this->script
     * @psalm-assert-if-true array{
     *     install:non-empty-string,
     *     update:non-empty-string
     * } $this->dependencies
     */
    public function isValid(): bool
    {
        $script = $this->scriptCmd('test') ?? '';
        $install = $this->dependencies[self::DEPS_INSTALL] ?? '';
        $update = $this->dependencies[self::DEPS_UPDATE] ?? '';

        return ($script !== '') && ($install !== '') && ($update !== '');
    }

    /**
     * @return bool
     */
    public function isNpm(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($this->name === self::NPM) {
            return true;
        }

        $isNpm = str_contains($this->script, 'npm');
        $isNpm and $this->name = self::NPM;

        return $isNpm;
    }

    /**
     * @return bool
     */
    public function isYarn(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($this->name === self::YARN) {
            return true;
        }

        $isYarn = str_contains($this->script, 'yarn');
        $isYarn and $this->name = self::YARN;

        return $isYarn;
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
     * @return PackageManager
     */
    public function withDefaultEnv(): PackageManager
    {
        return new self([self::DEPENDENCIES => $this->dependencies, self::SCRIPT => $this->script]);
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
        if (($this->script === null) || ($this->script === '')) {
            return null;
        }

        $command = Env::replaceEnvVariables($command, $env);

        // To pass arguments to scripts defined in package.json, npm requires `--` to be used,
        // whereas Yarn requires the arguments to be appended to script name.
        // For example, `npm run foo -- --bar=baz` is equivalent to `yarn foo --bar=baz`.
        // This is why if the command defined in "script" contains ` -- ` and we are using Yarn
        // then we remove `--`.
        $cmdParams = '';
        if (substr_count($command, ' -- ', 1) === 1) {
            /** @psalm-suppress PossiblyUndefinedArrayOffset */
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
        if (($dependencies !== []) && is_array($dependencies)) {
            $install = $dependencies[self::DEPS_INSTALL] ?? null;
            $update = $dependencies[self::DEPS_UPDATE] ?? null;
            (($install !== '') && is_string($install)) or $install = null;
            (($update !== '') && is_string($update)) or $update = null;
        }

        /** @var non-empty-string|null $install */
        /** @var non-empty-string|null $update */

        if (($install === null) && ($update !== null)) {
            $install = $update;
        } elseif (($update === null) && ($install !== null)) {
            $update = $install;
        }

        return [self::DEPS_INSTALL => $install, self::DEPS_UPDATE => $update];
    }

    /**
     * @param array $config
     * @return string|null
     */
    private function parseScript(array $config): ?string
    {
        $script = $config[self::SCRIPT] ?? null;
        if (is_string($script) && substr_count($script, '%s') === 1) {
            return $script;
        }

        return null;
    }

    /**
     * @param string|null $cmd
     * @param Io $io
     * @return string|null
     */
    private function maybeVerbose(?string $cmd, Io $io): ?string
    {
        if (($cmd === null) || ($cmd === '')) {
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

        return match (true) {
            $io->isQuiet() => "{$cmd} --silent",
            $io->isVeryVeryVerbose() => "{$cmd} -ddd",
            $io->isVeryVerbose() => "{$cmd} -dd",
            default => $io->isVerbose() ? "{$cmd} -d" : $cmd,
        };
    }
}
