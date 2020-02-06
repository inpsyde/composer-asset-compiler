<?php declare(strict_types=1);
/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Inpsyde\AssetsCompiler;

use Composer\Util\ProcessExecutor;

class Commands
{
    private const DEPENDENCIES = 'dependencies';
    private const DEPENDENCIES_INSTALL = 'install';
    private const DEPENDENCIES_UPDATE = 'update';
    private const DISCOVER = 'discover';
    private const SCRIPT = 'script';

    private const YARN = 'yarn';
    private const NPM = 'npm';

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
     * @var array{update: null|string, install: null|string}
     */
    private $dependencies = [
        self::DEPENDENCIES_UPDATE => null,
        self::DEPENDENCIES_INSTALL => null,
    ];

    /**
     * @var string|null
     */
    private $script;

    /**
     * @var array
     */
    private $defaultEnvironment;

    /**
     * @param string $manager
     * @param array $defaultEnvironment
     * @return Commands
     */
    public static function fromDefault(string $manager, array $defaultEnvironment = []): Commands
    {
        $manager = strtolower($manager);

        if (!array_key_exists($manager, self::SUPPORTED_DEFAULTS)) {
            return new static([], $defaultEnvironment);
        }

        return new static(self::SUPPORTED_DEFAULTS[$manager], $defaultEnvironment);
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

        foreach (self::SUPPORTED_DEFAULTS as $name => $data) {
            $discover = (string)($data[self::DISCOVER] ?? '');

            if ($discover && $executor->execute($discover, $out, $workingDir) === 0) {
                return static::fromDefault($name, $defaultEnvironment);
            }
        }

        return new static([], $defaultEnvironment);
    }

    /**
     * @param array $config
     * @param array $defaultEnvironment
     */
    public function __construct(array $config, array $defaultEnvironment = [])
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
        return $this->installCmd() && $this->updateCmd() && $this->scriptCmd('test');
    }

    /**
     * @return string|null
     */
    public function installCmd(): ?string
    {
        return $this->dependencies[self::DEPENDENCIES_INSTALL];
    }

    /**
     * @return string|null
     */
    public function updateCmd(): ?string
    {
        return $this->dependencies[self::DEPENDENCIES_UPDATE];
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

        if (strpos($command, '${') !== false) {
            $allEnv = array_merge($env, $this->defaultEnvironment);
            $command = (string)preg_replace_callback(
                '~\$\{([a-z0-9_]+)\}~i',
                static function (array $var) use ($allEnv): string {
                    return (string)EnvResolver::readEnv((string)$var[1], $allEnv);
                },
                $command
            );
        }

        return sprintf($this->script, $command);
    }
}
