<?php

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