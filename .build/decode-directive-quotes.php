<?php

/**
 * Replace &quot; with " inside the {{...}} directives of the downloaded email templates.
 *
 * The Crowdin HTML export encodes each quote in an attribute value, also inside a directive:
 * href="{{store url=&quot;customer/account/confirm/&quot;}}". The Maho template filter reads
 * only " and ' as quotes, so the link breaks.
 *
 * Usage: php .build/decode-directive-quotes.php
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

chdir(dirname(__DIR__));

$changed = 0;
foreach (glob('[a-z][a-z]_[A-Z][A-Z]', GLOB_ONLYDIR) ?: [] as $locale) {
    if (!is_dir("{$locale}/template")) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$locale}/template", FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'html') {
            continue;
        }
        $path = $file->getPathname();
        $content = (string) file_get_contents($path);
        $decoded = preg_replace_callback(
            '/\{\{.*?\}\}/s',
            static fn(array $match): string => str_replace('&quot;', '"', $match[0]),
            $content,
        );
        if ($decoded !== $content) {
            file_put_contents($path, $decoded);
            $changed++;
        }
    }
}

echo "Decoded the quotes inside the directives of {$changed} templates.\n";
