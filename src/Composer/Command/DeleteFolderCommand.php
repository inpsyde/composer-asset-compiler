<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

use Composer\Command\BaseCommand;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Usage: composer filesystem-delete-folder --path="some"
 */
final class DeleteFolderCommand extends BaseCommand
{
    public const COMMAND_NAME = 'filesystem-delete-folder';
    public const OPTION_PATH = 'path';

    /**
     * @return void
     */
    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName(static::COMMAND_NAME)
            ->setDescription('Deletes a folder using PHP filesystem service')
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'The path to delete folder'
            );
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
        $path = (string) $input->getOption(static::OPTION_PATH);
        $filesystem = new Filesystem();
        $output->writeln(sprintf('Deleting the directory in: %s', $path));

        if (!is_dir($path)) {
            return 2;
        }

        $filesystem->removeDirectory($path);

        return 0;
    }
}
