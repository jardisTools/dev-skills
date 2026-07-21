<?php

declare(strict_types=1);

/**
 * Generates (or checks) skills/jardis-catalog/SKILL.md from catalog/manifest.json.
 *
 * Deterministic: given the same manifest this script always produces
 * byte-identical output. CI uses `--check` to detect drift.
 *
 * Usage:
 *   php bin/generate-catalog.php            # generate / overwrite
 *   php bin/generate-catalog.php --check    # compare only; exit 1 on drift
 */

require __DIR__ . '/../vendor/autoload.php';

use JardisTools\DevSkills\CheckCatalogDrift;
use JardisTools\DevSkills\GenerateCatalogSkill;

$repoRoot     = dirname(__DIR__);
$manifestPath = $repoRoot . '/catalog/manifest.json';
$targetPath   = $repoRoot . '/skills/jardis-catalog/SKILL.md';

$checkMode = in_array('--check', $argv, true);

if ($checkMode) {
    try {
        (new CheckCatalogDrift())($manifestPath, $targetPath);
        fwrite(STDOUT, "OK: skills/jardis-catalog/SKILL.md matches the manifest.\n");
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    (new GenerateCatalogSkill())($manifestPath, $targetPath);
    fwrite(STDOUT, "Generated: {$targetPath}\n");
}
