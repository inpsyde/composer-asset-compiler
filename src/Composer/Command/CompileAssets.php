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
use Composer\IO\IOInterface;
use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Composer\Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CompileAssets extends BaseCommand
{
    use LowLevelErrorWriteTrait;

    /**
     * @return void
     *
     * @psalm-suppress MissingReturnType
     * @psalm-suppress MixedMethodCall
     * @psalm-suppress MixedArgument
     */
    protected function configure()
    {
        $this
            ->setName('compile-assets')
            ->setDescription('Run assets compilation workflow.')
            ->addOption(
                'no-dev',
                null,
                InputOption::VALUE_NONE,
                'Tell the command to fallback to no-dev env configuration.'
            )
            ->addOption(
                'env',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the environment to run command in. '
                . 'Overrides value of COMPOSER_ASSETS_COMPILER, if set.'
            )
            ->addOption(
                'ignore-lock',
                null,
                InputOption::VALUE_OPTIONAL,
                'Ignore lock for either all or specific packages.'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     *
     * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
     *
     * @psalm-suppress MissingReturnType
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // phpcs:enable

        try {
            /** @var Composer $composer */
            $composer = $this->getComposer(true, false);

            /** @var IOInterface $io */
            $io = $this->getIO();

            $plugin = new Plugin();
            $plugin->activate($composer, $io);

            $noDev = $input->hasOption('no-dev');
            $env = $input->hasOption('env') ? $input->getOption('env') : null;

            $ignoreLock = '';
            if ($input->hasOption('ignore-lock')) {
                $ignored = $input->getOption('ignore-lock');
                if ($ignored === true) {
                    $ignoreLock = Locker::IGNORE_ALL;
                } elseif ($ignored && is_string($ignored)) {
                    $ignoreLock = $ignored;
                }
            }

            $plugin->runByCommand(is_string($env) ? $env : null, !$noDev, $ignoreLock);

            return 0;
        } catch (\Throwable $throwable) {
            $this->writeError($output, $throwable->getMessage());

            return 1;
        }
    }
}
