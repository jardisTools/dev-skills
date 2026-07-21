<?php

declare(strict_types=1);

/**
 * Additively checks whether any Packagist-listed Jardis package is missing
 * from catalog/manifest.json. Prints a WARNING line for each missing package.
 *
 * This script is informational only: it always exits 0, even when warnings
 * are printed. Fetch failures (offline, HTTP error) are silently skipped.
 *
 * Usage:
 *   php bin/check-catalog-packagist.php
 */

require __DIR__ . '/../vendor/autoload.php';

use JardisTools\DevSkills\Handler\Build\CheckCatalogPackagist;
use JardisTools\DevSkills\Handler\Build\HttpPackagistFetcher;

$manifestPath = dirname(__DIR__) . '/catalog/manifest.json';

$warnings = (new CheckCatalogPackagist())($manifestPath, new HttpPackagistFetcher());

foreach ($warnings as $warning) {
    fwrite(STDOUT, $warning . "\n");
}

if ($warnings === []) {
    fwrite(STDOUT, "[catalog-packagist] OK: no missing packages detected.\n");
}
