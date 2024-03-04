<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

use Inpsyde\AssetsCompiler\Util\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends \Composer\Command\BaseCommand
{
    /**
     * @return static
     */
    protected function configureCommon(): static
    {
        $this->addOption(
            'no-dev',
            null,
            InputOption::VALUE_NONE,
            'Tell the command to fallback to no-dev mode configuration.'
        );
        $this->addOption(
            'mode',
            null,
            InputOption::VALUE_REQUIRED,
            'Set the mode to run command in. Overrides value of COMPOSER_ASSETS_COMPILER, if set.'
        );

        return $this;
    }

    /**
     * @param InputInterface $input
     * @return Factory
     */
    protected function createFactory(InputInterface $input): Factory
    {
        return Factory::new(
            $this->requireComposer(false),
            $this->getIO(),
            $this->determineMode($input),
            !$input->hasOption('no-dev')
        );
    }

    /**
     * @param InputInterface $input
     * @return string|null
     */
    protected function determineMode(InputInterface $input): ?string
    {
        $mode = $input->hasParameterOption('--mode') ? $input->getOption('mode') : null;
        is_string($mode) or $mode = null;

        return $mode;
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return void
     */
    protected function writeError(OutputInterface $output, string $message): void
    {
        $words = explode(' ', $message);
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if (strlen($line . $word) < 60) {
                $line .= $line ? " {$word}" : $word;
                continue;
            }

            $lines[] = "  {$line}  ";
            $line = $word;
        }

        $line and $lines[] = "  {$line}  ";

        $lenMax = $lines ? max(array_map('strlen', $lines)) : 1;
        $empty = '<error>' . str_repeat(' ', $lenMax) . '</error>';
        $errors = ['', $empty];
        foreach ($lines as $line) {
            $lineLen = strlen($line);
            ($lineLen < $lenMax) and $line .= str_repeat(' ', $lenMax - $lineLen);
            $errors[] = "<error>{$line}</error>";
        }

        $errors[] = $empty;
        $errors[] = '';

        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        $output->writeln($errors);
    }
}
