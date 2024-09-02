<?php

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'translate',
    description: 'Translate CSV files line by line from en_US to a target locale using GROQ API with llama-3.1-70b-versatile model'
)]
class Translate extends Command
{
    const GROQ_API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    const GROQ_MODEL = 'llama-3.1-70b-versatile';
    const MAGENTO2_BASE_URL = 'https://raw.githubusercontent.com/magento-l10n/language-{locale}/master/{locale}.csv';
    const OPENMAGE_BASE_URL = 'https://raw.githubusercontent.com/luigifab/openmage-translations/main/locales/{locale}/{filename}';
    const SOURCE_DIRECTORY = './en_US/';

    private $magento2Translations = [];
    private $openMageTranslations = [];
    private $locale;

    protected function configure(): void
    {
        $this
            ->addArgument('locale', InputArgument::REQUIRED, 'The target locale code (e.g., it_IT)')
            ->addArgument('api-key', InputArgument::REQUIRED, 'Your GROQ API key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->locale = $input->getArgument('locale');
        $apiKey = $input->getArgument('api-key');
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

        // Load Magento 2 translations before processing files
        $this->loadMagento2Translations($output);

        $csvFiles = glob(self::SOURCE_DIRECTORY . '/*.csv');
        foreach ($csvFiles as $sourceFilePath) {
            $relativeFilePath = str_replace(self::SOURCE_DIRECTORY, '', $sourceFilePath);
            $targetFilePath = $targetDirectory . $relativeFilePath;

            if (file_exists($targetFilePath)) {
                $output->writeln("<info>Skipping existing file: $targetFilePath</info>");
                continue;
            }

            $output->writeln("Translating CSV file: $sourceFilePath");
            try {
                // Load OpenMage translations for the current file
                $this->loadOpenMageTranslations($output, basename($sourceFilePath));
                $this->processCSV($sourceFilePath, $targetFilePath, $apiKey, $output);
            } catch (\Exception $e) {
                $output->writeln("<error>Error processing file $sourceFilePath: " . $e->getMessage() . "</error>");
                return Command::FAILURE;
            }
        }

        $output->writeln("<info>Translation process completed.</info>");
        return Command::SUCCESS;
    }

    private function loadMagento2Translations(OutputInterface $output): void
    {
        $magento2Url = str_replace('{locale}', $this->locale, self::MAGENTO2_BASE_URL);
        $output->writeln("<info>Loading translations from Magento 2: $magento2Url</info>");

        $content = @file_get_contents($magento2Url);
        if ($content === false) {
            $output->writeln("<error>Failed to load Magento 2 translations. Proceeding without them.</error>");
            return;
        }

        $rows = array_map('str_getcsv', explode("\n", $content));
        foreach ($rows as $row) {
            if (count($row) >= 2) {
                $this->magento2Translations[$row[0]] = $row[1];
            }
        }
        $output->writeln("<info>Loaded " . count($this->magento2Translations) . " translations from Magento 2.</info>");
    }

    private function loadOpenMageTranslations(OutputInterface $output, string $filename): void
    {
        $openMageUrl = str_replace(['{locale}', '{filename}'], [$this->locale, $filename], self::OPENMAGE_BASE_URL);
        $output->writeln("<info>Loading translations from OpenMage: $openMageUrl</info>");

        $content = @file_get_contents($openMageUrl);
        if ($content === false) {
            $output->writeln("<error>Failed to load OpenMage translations for $filename. Proceeding without them.</error>");
            return;
        }

        $this->openMageTranslations = []; // Clear previous translations
        $rows = array_map('str_getcsv', explode("\n", $content));
        foreach ($rows as $row) {
            if (count($row) >= 2) {
                $this->openMageTranslations[$row[0]] = $row[1];
            }
        }
        $output->writeln("<info>Loaded " . count($this->openMageTranslations) . " translations from OpenMage for $filename.</info>");
    }

    private function processCSV($sourceFilePath, $targetFilePath, $apiKey, OutputInterface $output): void
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

        while (($row = fgetcsv($inputFile)) !== false) {
            try {
                $translatedRow = $this->translateRow($row, $apiKey, $output);
                $this->writeQuotedCsvRow($outputFile, $translatedRow);
                $rowCount++;

                if ($rowCount % 10 === 0) {
                    $output->writeln("<info>Processed " . $rowCount . " rows</info>");
                }
            } catch (\Exception $e) {
                $output->writeln("<error>Error processing row: " . $e->getMessage() . "</error>");
                throw $e; // Re-throw the exception to be caught by the execute method
            }
        }

        $output->writeln("Processed a total of " . $rowCount . " rows");

        fclose($inputFile);
        fclose($outputFile);
    }

    private function translateRow($row, $apiKey, OutputInterface $output): array
    {
        if (count($row) > 1) {
            $identifier = $row[0];
            $content = $row[1];

            // Check if translation exists in Magento 2 data
            if (isset($this->magento2Translations[$identifier])) {
                $row[1] = $this->magento2Translations[$identifier];
                $output->writeln("Using Magento 2 for: $identifier");
            }
            // Check if translation exists in OpenMage data
            elseif (isset($this->openMageTranslations[$identifier])) {
                $row[1] = $this->openMageTranslations[$identifier];
                $output->writeln("Using OpenMage for: $identifier");
            }
            else {
                $translatedContent = $this->translateContent($content, $apiKey, $output);
                $row[1] = $translatedContent;
            }
        }
        return $row;
    }

    private function writeQuotedCsvRow($file, $row): void
    {
        $quotedRow = array_map(function($field) {
            return '"' . str_replace('"', '""', $field) . '"';
        }, $row);
        fwrite($file, implode(',', $quotedRow) . "\n");
    }

    private function translateContent($content, $apiKey, OutputInterface $output): string
    {
        $output->writeln("Using AI for: $content");

        $maxRetries = 3;
        $retryCount = 0;
        $waitTime = 10; // seconds

        // Capture leading and trailing spaces
        $leadingSpaces = strlen($content) - strlen(ltrim($content));
        $trailingSpaces = strlen($content) - strlen(rtrim($content));

        while ($retryCount < $maxRetries) {
            sleep(1);
            try {
                $data = [
                    'model' => self::GROQ_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => <<<EOF
You are a translation assistant. Your task is to translate the given content to the specified locale.
- Consider that the context is an ecommerce software/website. For example (in italian) "run" should be translated to "esegui", not "corri". Use this same logic for every language.
- Think about the context (ecommerce website/software/platform) and make sure your translation makes sense in the context
- Only output the translated content, nothing else, no comments or anything
- Every time you see the word openmage or magento, translate it to Maho
- Try not to translate specific terms like "url rewrites" or "layered navigation" or others that may sound weird in the target language 
- Maintain the casing of the words if possible
- Preserve any special characters or formatting in the original text
- You have to translate every message you receive, whatever it means
EOF
                        ],
                        ['role' => 'user', 'content' => "Translate the following content to {$this->locale}:\n\n\"$content\""]
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 2000,
                ];

                $ch = curl_init(self::GROQ_API_URL);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($response === false) {
                    $error = curl_error($ch);
                    curl_close($ch);
                    throw new \Exception("API call failed: $error");
                }

                curl_close($ch);

                if ($httpCode != 200) {
                    throw new \Exception("API call failed with HTTP code $httpCode");
                }

                $result = json_decode($response, true);
                if (!isset($result['choices'][0]['message']['content'])) {
                    throw new \Exception("Unexpected API response format");
                }

                $translatedContent = $result['choices'][0]['message']['content'];

                // Remove surrounding quotes if they were added by the API
                if (substr($translatedContent, 0, 1) === '"' && substr($translatedContent, -1) === '"') {
                    $translatedContent = substr($translatedContent, 1, -1);
                }

                if (empty($translatedContent)) {
                    throw new \Exception("Empty translation received");
                }

                // Ensure the translated content has the same leading and trailing spaces as the original
                $translatedLeadingSpaces = strlen($translatedContent) - strlen(ltrim($translatedContent));
                $translatedTrailingSpaces = strlen($translatedContent) - strlen(rtrim($translatedContent));

                if ($translatedLeadingSpaces < $leadingSpaces) {
                    $translatedContent = str_repeat(' ', $leadingSpaces - $translatedLeadingSpaces) . $translatedContent;
                }

                if ($translatedTrailingSpaces < $trailingSpaces) {
                    $translatedContent .= str_repeat(' ', $trailingSpaces - $translatedTrailingSpaces);
                }

                return $translatedContent;

            } catch (\Exception $e) {
                $output->writeln("<error>API Error: " . $e->getMessage() . "</error>");

                if ($retryCount < $maxRetries - 1) {
                    $output->writeln("<info>Retrying in $waitTime seconds...</info>");
                    sleep($waitTime);
                    $retryCount++;
                } else {
                    $output->writeln("<error>Max retries reached. Exiting...</error>");
                    throw $e; // Re-throw the exception to be caught by the calling method
                }
            }
        }

        throw new \Exception("Failed to translate content after $maxRetries attempts");
    }
}