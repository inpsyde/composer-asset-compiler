<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Composer\Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CompileAssets extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->configureCommon()
            ->setName('compile-assets')
            ->setDescription('Run assets compilation workflow.')
            ->addOption(
                'ignore-lock',
                null,
                InputOption::VALUE_OPTIONAL,
                'Ignore lock for either all or specific packages.',
                Locker::IGNORE_ALL
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return 0|1
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $composer = $this->requireComposer(false);
            $io = $this->getIO();

            $plugin = new Plugin();
            $plugin->activate($composer, $io);

            $noDev = $input->hasOption('no-dev');
            $mode = $this->determineMode($input);

            $ignoreLockRaw = $input->hasParameterOption('--ignore-lock', true)
                ? $input->getOption('ignore-lock')
                : null;
            $ignoreLock = is_string($ignoreLockRaw) ? $ignoreLockRaw : '';
            ($ignoreLock === '*/*') and $ignoreLock = Locker::IGNORE_ALL;

            $plugin->runByCommand(
                is_string($mode) ? $mode : null,
                !$noDev,
                $ignoreLock
            );

            return 0;
        } catch (\Throwable $throwable) {
            $this->writeError($output, $throwable->getMessage());

            return 1;
        }
    }
}
