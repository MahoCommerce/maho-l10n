<?php

/**
 * Find translations that do not keep the {{...}} directives of their source.
 *
 * Usage: php .build/check-directives.php [locale ...]
 * Without a locale, the script checks every locale folder.
 * The exit code is 1 when the script finds an error.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

chdir(dirname(__DIR__));

/**
 * @return list<string>
 */
function directives(string $text): array
{
    preg_match_all('/\{\{.*?\}\}/s', $text, $matches);
    $list = $matches[0];
    sort($list);
    return $list;
}

/**
 * @param list<string> $expected
 * @param list<string> $actual
 */
function difference(array $expected, array $actual): string
{
    $missing = $expected;
    $extra = [];
    foreach ($actual as $directive) {
        $key = array_search($directive, $missing, true);
        if ($key === false) {
            $extra[] = $directive;
        } else {
            unset($missing[$key]);
        }
    }
    $parts = [];
    if ($missing) {
        $parts[] = 'missing ' . implode(' ', $missing);
    }
    if ($extra) {
        $parts[] = 'unexpected ' . implode(' ', $extra);
    }
    return implode('; ', $parts);
}

function report(string $file, string $message): void
{
    echo '::error file=' . $file . '::' . str_replace(["\r", "\n"], ' ', $message) . "\n";
}

$locales = array_slice($argv, 1);
if (!$locales) {
    $locales = array_map('basename', glob('[a-z][a-z]_[A-Z][A-Z]', GLOB_ONLYDIR) ?: []);
}
$locales = array_values(array_diff($locales, ['en_US']));

$templates = [];
$folders = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('en_US/template', FilesystemIterator::SKIP_DOTS));
foreach ($folders as $file) {
    if ($file->getExtension() === 'html') {
        $templates[] = substr($file->getPathname(), strlen('en_US/'));
    }
}
sort($templates);

$errors = 0;
foreach ($locales as $locale) {
    if (!is_dir($locale)) {
        report($locale, 'The locale folder does not exist');
        $errors++;
        continue;
    }

    foreach ($templates as $template) {
        $file = "{$locale}/{$template}";
        if (!is_file($file)) {
            continue;
        }
        $expected = directives((string) file_get_contents("en_US/{$template}"));
        $actual = directives((string) file_get_contents($file));
        if ($expected !== $actual) {
            report($file, difference($expected, $actual));
            $errors++;
        }
    }

    foreach (glob("{$locale}/*.csv") ?: [] as $file) {
        $handle = fopen($file, 'r');
        $line = 0;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if (count($row) < 2) {
                continue;
            }
            $expected = directives((string) $row[0]);
            $actual = directives((string) $row[1]);
            if ($expected !== $actual) {
                report("{$file},line={$line}", difference($expected, $actual));
                $errors++;
            }
        }
        fclose($handle);
    }
}

if ($errors) {
    echo "Found {$errors} translations that do not keep the {{...}} directives of the source. Fix them in Crowdin.\n";
    exit(1);
}
echo 'All translations keep the {{...}} directives of the source in: ' . implode(' ', $locales) . "\n";
