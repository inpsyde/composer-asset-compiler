<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Composer\Command;

use Inpsyde\AssetsCompiler\Util\Factory;
use Inpsyde\AssetsCompiler\Util\Io;
use phpDocumentor\Reflection\Types\Scalar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

final class AssetsInfo extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->configureCommon()
            ->setName('assets-info')
            ->setDescription('Gets assets compilation information.')
            ->addArgument(
                'asset',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Limits info to given asset(s) only. Can not be used with --root flag.',
                null
            )
            ->addOption(
                'root',
                null,
                InputOption::VALUE_NONE,
                'Limits info to root asset only. Can not be used when passing asset names.'
            )
            ->addOption(
                'table',
                null,
                InputOption::VALUE_NONE,
                'Prints output as table. Use --fields flag to get readable results.'
            )
            ->addOption(
                'fields',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated fields to print.'
            )
            ->addOption(
                'all-watchable-paths',
                null,
                InputOption::VALUE_NONE,
                'Return only watchable paths for all assets.'
                . ' Can not be used in combination with asset names or --fields flag.'
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
            $factory = $this->createFactory($input);
            [$pathsOnly, $doRoot, $doAssets, $doOneAsset, $format] = $this->parseParams($input);

            $info = $factory->assetsInfo();

            if ($pathsOnly) {
                return $this->executePathsOnly($factory, $format, $output);
            }

            /** @var non-empty-list<non-empty-array> $data */
            $data = match (true) {
                ($doOneAsset !== null) => [$info->assetInfo($doOneAsset)],
                ($doAssets !== []) => $info->assetInfo(...$doAssets),
                $doRoot => [$info->rootAssetInfo()],
                default => $info->allAssetsInfo(),
            };

            $data = $this->filterData($data, $input);
            $this->format($factory->io(), $output, $format, $data);

            return 0;
        } catch (\Throwable $throwable) {
            $this->writeError($output, $throwable->getMessage());

            return 1;
        }
    }

    /**
     * @param InputInterface $input
     * @return list{bool, bool, list<non-empty-string>, non-empty-string|null, 'table'|'json'}
     */
    private function parseParams(InputInterface $input): array
    {
        $pathsOnly = $input->hasParameterOption('--all-watchable-paths');
        $doRoot = $input->hasParameterOption('--root');
        $doAssets = $this->targetAssetNames($input);
        $doOneAsset = (count($doAssets) === 1) ? $doAssets[0] : null;
        $format = $input->hasParameterOption('--table') ? 'table' : 'json';

        if (
            $pathsOnly
            && ($doRoot || ($doAssets !== []) || $input->hasParameterOption('--fields'))
        ) {
            throw new \Error(
                '--all-watchable-paths flag can not be used when passing asset names nor'
                . ' when using --fields --root flags.'
            );
        }

        if ($doRoot && ($doAssets !== [])) {
            throw new \Error('--root flag can not be used when passing asset names.');
        }

        return [$pathsOnly, $doRoot, $doAssets, $doOneAsset, $format];
    }

    /**
     * @param Factory $factory
     * @param string $format
     * @param OutputInterface $output
     * @return 0
     */
    private function executePathsOnly(
        Factory $factory,
        string $format,
        OutputInterface $output
    ): int {

        $paths = $factory->assetsPathsFinder()->findAllAssetsPaths();
        $paths = ($format === 'table') ? [compact('paths')] : [$paths];

        $this->format($factory->io(), $output, $format, $paths);

        return 0;
    }

    /**
     * @param non-empty-list<non-empty-array> $data
     * @param InputInterface $input
     * @return non-empty-list<non-empty-array>
     */
    private function filterData(array $data, InputInterface $input): array
    {
        if (!$input->hasParameterOption('--fields')) {
            return $data;
        }

        $input = $input->getOption('fields');
        if (($input === '') || !is_string($input)) {
            return $data;
        }

        $fields = [];
        foreach (explode(',', $input) as $field) {
            $field = strtolower(trim($field));
            if (($field !== '') && !isset($fields[$field])) {
                $fields[$field] = true;
            }
        }

        if ($fields === []) {
            return $data;
        }

        $filtered = [];
        foreach ($data as $item) {
            $filteredItem = array_intersect_key($item, $fields);
            if ($filteredItem === []) {
                throw new \Error("Invalid --fields flag: '{$input}'.");
            }
            $filtered[] = $filteredItem;
        }

        return $filtered;
    }

    /**
     * @param InputInterface $input
     * @return list<non-empty-string>
     */
    private function targetAssetNames(InputInterface $input): array
    {
        if (!$input->hasArgument('asset')) {
            return [];
        }

        $input = $input->getArgument('asset');
        if (is_string($input) && ($input !== '')) {
            return [$input];
        }

        if (!is_array($input)) {
            return [];
        }

        $inputs = [];
        foreach ($input as $asset) {
            if (is_string($asset) && ($asset !== '')) {
                $inputs[] = $asset;
            }
        }

        return $inputs;
    }

    /**
     * @param Io $io
     * @param OutputInterface $output
     * @param string $format
     * @param non-empty-list<non-empty-array> $data
     * @return void
     */
    private function format(Io $io, OutputInterface $output, string $format, array $data): void
    {
        match ($format) {
            'json' => $this->printJson($data, $io),
            default => $this->printTable($data, $output),
        };
    }

    /**
     * @param non-empty-list<non-empty-array> $data
     * @param Io $io
     * @return void
     */
    private function printJson(array $data, Io $io): void
    {
        if (count($data) === 1) {
            $data = array_pop($data);
        }

        $json = json_encode(
            $data,
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
        );
        $io->writeRaw($json);
    }

    /**
     * @param non-empty-list<non-empty-array> $data
     * @param OutputInterface $output
     * @return void
     */
    private function printTable(array $data, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(array_keys($data[0]));
        foreach ($data as $row) {
            $table->addRow($this->formatTableRow($row));
        }

        $table->render();
    }

    /**
     * @param array $row
     * @return list<scalar|null>
     */
    private function formatTableRow(array $row): array
    {
        $cols = [];

        foreach ($row as $col) {
            if (($col === null) || is_scalar($col)) {
                $cols[] = $col;
                continue;
            }
            if (!is_array($col)) {
                $cols[] = get_debug_type($col);
                continue;
            }

            if ($col === []) {
                $cols[] = '[]';
                continue;
            }

            $safeCol = [];
            foreach ($col as $key => $value) {
                $safeCol[$key] = is_scalar($value) ? (string) $value : get_debug_type($value);
            }

            if (str_starts_with((string) json_encode($safeCol), '[')) {
                $cols[] = '- ' . implode("\n- ", $safeCol);
                continue;
            }

            $formatted = '';
            foreach ($safeCol as $key => $value) {
                $formatted .= sprintf("- %s: %s\n", $key, $value);
            }
            $cols[] = rtrim($formatted);
        }

        return $cols;
    }
}
