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

trait ModeOptionTrait
{
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string|null
     */
    private function determineMode(InputInterface $input, OutputInterface $output): ?string
    {
        $mode = $input->hasOption('mode') ? $input->getOption('mode') : null;
        is_string($mode) or $mode = null;
        if ($mode !== null) {
            return $mode;
        }

        if (!$input->hasOption('env')) {
            return null;
        }

        $env = $input->getOption('env');
        is_string($env) or $env = null;

        if ($env !== null) {
            $output->writeln("Option 'env' is deprecated, please use 'mode' instead");
        }

        return $env;
    }
}
