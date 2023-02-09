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

    /**
     * @return void
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
                'hash-seed',
                null,
                InputOption::VALUE_REQUIRED,
                'See to be used in the generation of assets-hash.'
            )
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
     * @return int
     *
     * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // phpcs:enable Inpsyde.CodeQuality.ReturnTypeDeclaration

        try {
            /**
             * @psalm-suppress DeprecatedMethod
             * @var Composer $composer
             */
            $composer = $this->getComposer(true, false);
            $io = $this->getIO();

            $plugin = new Plugin();
            $plugin->activate($composer, $io);

            $noDev = $input->hasOption('no-dev');
            $env = $input->hasOption('env') ? $input->getOption('env') : null;

            $seed = $input->hasOption('hash-seed') ? $input->getOption('hash-seed') : null;
            is_string($seed) or $seed = null;
            $seed and $seed = trim($seed);

            $ignoreLockRaw = $input->hasParameterOption('--ignore-lock', true)
                ? $input->getOption('ignore-lock')
                : null;
            $ignoreLock = ($ignoreLockRaw && is_string($ignoreLockRaw)) ? $ignoreLockRaw : '';
            ($ignoreLock === '*/*') and $ignoreLock = Locker::IGNORE_ALL;

            $plugin->runByCommand(
                is_string($env) ? $env : null,
                !$noDev,
                $ignoreLock,
                $seed ?: null
            );

            return 0;
        } catch (\Throwable $throwable) {
            $this->writeError($output, $throwable->getMessage());

            return 1;
        }
    }
}
