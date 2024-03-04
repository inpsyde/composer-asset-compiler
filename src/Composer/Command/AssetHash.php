<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AssetHash extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->configureCommon()
            ->setName('asset-hash')
            ->setDescription('Calculate assets hash for root package in current environment.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return 0|1
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $factory = $this->createFactory($input);

            $package = $this->requireComposer(false)->getPackage();
            $defaults = $factory->defaults();
            $asset = $factory->assetFactory()->attemptFactory($package, null, $defaults);
            $hash = $asset ? $factory->hashBuilder()->forAsset($asset) : null;

            if ($hash === null) {
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
