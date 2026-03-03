<?php

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'translate',
    description: 'Generate translated CSV files from en_US using Magento 2 community translations'
)]
class Translate extends Command
{
    const MAGENTO2_BASE_URL = 'https://raw.githubusercontent.com/magento-l10n/language-{locale}/master/{locale}.csv';
    const SOURCE_DIRECTORY = './en_US/';

    private $magento2Translations = [];
    private $locale;

    protected function configure(): void
    {
        $this
            ->addArgument('locale', InputArgument::REQUIRED, 'The target locale code (e.g., it_IT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->locale = $input->getArgument('locale');
        $targetDirectory = "./{$this->locale}/";

        if (!is_dir(self::SOURCE_DIRECTORY)) {
            $output->writeln("<error>Error: The source directory ./en_US does not exist.</error>");
            return Command::FAILURE;
        }

        if (!is_dir($targetDirectory)) {
            if (!mkdir($targetDirectory, 0777, true)) {
                $output->writeln("<error>Error: Unable to create the target directory $targetDirectory.</error>");
                return Command::FAILURE;
            }
            $output->writeln("<info>Created target directory: $targetDirectory</info>");
        }

        $this->loadMagento2Translations($output);

        if (empty($this->magento2Translations)) {
            $output->writeln("<error>No Magento 2 translations found for {$this->locale}. Aborting.</error>");
            return Command::FAILURE;
        }

        $csvFiles = glob(self::SOURCE_DIRECTORY . '/*.csv');
        $totalTranslated = 0;
        $totalRows = 0;

        foreach ($csvFiles as $sourceFilePath) {
            $relativeFilePath = str_replace(self::SOURCE_DIRECTORY, '', $sourceFilePath);
            $targetFilePath = $targetDirectory . $relativeFilePath;

            $output->writeln("Processing: $relativeFilePath");
            [$translated, $rows] = $this->processCSV($sourceFilePath, $targetFilePath, $output);
            $totalTranslated += $translated;
            $totalRows += $rows;
        }

        // Copy email templates
        $templatesCopied = $this->copyTemplates($targetDirectory, $output);

        $percentage = $totalRows > 0 ? round($totalTranslated / $totalRows * 100, 1) : 0;
        $output->writeln("");
        $output->writeln("<info>Done. Translated $totalTranslated/$totalRows strings ($percentage%) for {$this->locale}.</info>");
        $output->writeln("<info>Copied $templatesCopied email templates.</info>");
        $output->writeln("<info>Remaining strings need translation via Crowdin.</info>");

        return Command::SUCCESS;
    }

    private function loadMagento2Translations(OutputInterface $output): void
    {
        $magento2Url = str_replace('{locale}', $this->locale, self::MAGENTO2_BASE_URL);
        $output->writeln("<info>Loading translations from Magento 2: $magento2Url</info>");

        $content = @file_get_contents($magento2Url);
        if ($content === false) {
            $output->writeln("<error>Failed to load Magento 2 translations.</error>");
            return;
        }

        $rows = array_map(fn($line) => str_getcsv($line, escape: "\\"), explode("\n", $content));
        foreach ($rows as $row) {
            if (count($row) >= 2) {
                $this->magento2Translations[$row[0]] = $row[1];
            }
        }
        $output->writeln("<info>Loaded " . count($this->magento2Translations) . " translations from Magento 2.</info>");
    }

    private function processCSV(string $sourceFilePath, string $targetFilePath, OutputInterface $output): array
    {
        $targetFileDir = dirname($targetFilePath);
        if (!is_dir($targetFileDir)) {
            mkdir($targetFileDir, 0777, true);
        }

        $inputFile = fopen($sourceFilePath, 'r');
        $outputFile = fopen($targetFilePath, 'w');

        if (!$inputFile || !$outputFile) {
            throw new \Exception("Unable to open input or output file");
        }

        $rowCount = 0;
        $translatedCount = 0;

        while (($row = fgetcsv($inputFile, escape: "\\")) !== false) {
            if (count($row) >= 2) {
                $source = $row[0];
                if (isset($this->magento2Translations[$source])) {
                    $row[1] = $this->magento2Translations[$source];
                    $translatedCount++;
                }
            }
            $this->writeQuotedCsvRow($outputFile, $row);
            $rowCount++;
        }

        fclose($inputFile);
        fclose($outputFile);

        $output->writeln("  $translatedCount/$rowCount strings translated");

        return [$translatedCount, $rowCount];
    }

    private function copyTemplates(string $targetDirectory, OutputInterface $output): int
    {
        $templateDir = self::SOURCE_DIRECTORY . 'template';
        if (!is_dir($templateDir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateDir));

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = str_replace(self::SOURCE_DIRECTORY, '', $file->getPathname());
            $targetPath = $targetDirectory . $relativePath;
            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            copy($file->getPathname(), $targetPath);
            $count++;
        }

        $output->writeln("<info>Copied $count email templates from en_US.</info>");
        return $count;
    }

    private function writeQuotedCsvRow($file, $row): void
    {
        $quotedRow = array_map(function($field) {
            return '"' . str_replace('"', '""', $field) . '"';
        }, $row);
        fwrite($file, implode(',', $quotedRow) . "\n");
    }
}
