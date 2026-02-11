<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Composer\Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CompileAssets extends BaseCommand
{
    use LowLevelErrorWriteTrait;
    use ModeOptionTrait;
    use ObtainComposerTrait;

    public const OPTION_CLEAR_PACKAGE_MANAGER_CACHE = 'clear-cache-between-batches';
    public const OPTION_FORCE_DELETE_NODE_MODULES = 'force-delete-node-modules';
    public const OPTION_MAX_PARALLEL_PROCESSES = 'max-parallel-processes';

    /**
     * @return void
     */
    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('compile-assets')
            ->setDescription('Run assets compilation workflow.')
            ->addOption(
                'no-dev',
                null,
                InputOption::VALUE_NONE,
                'Tell the command to fallback to no-dev mode configuration.'
            )
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the mode to run command in. '
                . 'Overrides value of COMPOSER_ASSETS_COMPILER, if set.'
            )
            ->addOption(
                'env',
                null,
                InputOption::VALUE_REQUIRED,
                'DEPRECATED. Use "mode" instead'
            )
            ->addOption(
                'ignore-lock',
                null,
                InputOption::VALUE_OPTIONAL,
                'Ignore lock for either all or specific packages.',
                Locker::IGNORE_ALL
            )
            ->addOption(
                self::OPTION_CLEAR_PACKAGE_MANAGER_CACHE,
                null,
                InputOption::VALUE_NONE,
                'Should the cache be cleared after processing a batch of groups?.'
            )
            ->addOption(
                self::OPTION_FORCE_DELETE_NODE_MODULES,
                null,
                InputOption::VALUE_NONE,
                'Should the process force delete node_modules folder?'
            )
            ->addOption(
                self::OPTION_MAX_PARALLEL_PROCESSES,
                null,
                InputOption::VALUE_REQUIRED,
                'The amount of parallel processes that can be ran in parallel'
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     *
     * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // phpcs:enable Inpsyde.CodeQuality.ReturnTypeDeclaration

        try {
            $composer = $this->obtainComposer();
            $io = $this->getIO();

            $plugin = new Plugin();
            $plugin->activate($composer, $io);

            $isDev = !$input->hasOption('no-dev');
            $mode = $this->determineMode($input, $output);

            $ignoreLockRaw = $input->hasParameterOption('--ignore-lock', true)
                ? $input->getOption('ignore-lock')
                : null;
            $ignoreLock = ($ignoreLockRaw && is_string($ignoreLockRaw)) ? $ignoreLockRaw : '';
            ($ignoreLock === '*/*') and $ignoreLock = Locker::IGNORE_ALL;
            $maxProcesses = $input->hasParameterOption('--' . self::OPTION_MAX_PARALLEL_PROCESSES)
                ? (int) $input->getOption(self::OPTION_MAX_PARALLEL_PROCESSES)
                : null;

            $commandPassedArguments = new CompileAssetsPassedArguments(
                $isDev,
                $ignoreLock,
                is_string($mode) ? $mode : null,
                $input->hasParameterOption('--' . self::OPTION_CLEAR_PACKAGE_MANAGER_CACHE),
                $input->hasParameterOption('--' . self::OPTION_FORCE_DELETE_NODE_MODULES),
                $maxProcesses
            );

            $plugin->runByCommand(
                $commandPassedArguments
            );

            return 0;
        } catch (\Throwable $throwable) {
            $this->writeError($output, $throwable->getMessage());

            return 1;
        }
    }
}
