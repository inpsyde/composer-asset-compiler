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
use Inpsyde\AssetsCompiler\Util\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AssetHash extends BaseCommand
{
    use LowLevelErrorWriteTrait;
    use ModeOptionTrait;
    use ObtainComposerTrait;

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('assets-hash')
            ->setDescription('Calculate assets hash for root package in current environment.')
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
        // phpcs:enable

        try {
            $composer = $this->obtainComposer();
            $io = $this->getIO();
            $noDev = $input->hasOption('no-dev');
            $mode = $this->determineMode($input, $output);

            $factory = Factory::new($composer, $io, $mode, !$noDev);
            $package = $composer->getPackage();
            $defaults = $factory->defaults();
            $asset = $factory->assetFactory()->attemptFactory($package, null, $defaults);
            $hash = $asset ? $factory->hashBuilder()->forAsset($asset) : null;

            if (!$hash) {
                throw new \Error('Could not generate a hash for the package.');
            }

            $output->write(
                $hash,
                false,
                OutputInterface::VERBOSITY_QUIET | OutputInterface::OUTPUT_PLAIN
            );

            return 0;
        } catch (\Throwable $throwable) {
            $this->writeError($output, $throwable->getMessage());

            return 1;
        }
    }
}
