<?php

declare(strict_types=1);

/**
 * Validates every bundled SKILL.md against the format rules in
 * docs/SKILL-FORMAT.md.
 *
 * Exits 0 when all skills are conformant; non-zero otherwise.
 *
 * Usage (typically via `make validate-skills`):
 *   php bin/validate-skills.php [<skill-dir> ...]
 *
 * With no arguments, scans skills/ in the repo root.
 */

require __DIR__ . '/../vendor/autoload.php';

use JardisTools\DevSkills\Handler\Validate\ValidateSkillMd;

$repoRoot   = dirname(__DIR__);
$skillsRoot = $repoRoot . '/skills';

$argList = array_slice($argv, 1);
if ($argList === []) {
    $skillFiles = glob($skillsRoot . '/*/SKILL.md') ?: [];
} else {
    $skillFiles = [];
    foreach ($argList as $arg) {
        if (is_dir($arg) && is_file($arg . '/SKILL.md')) {
            $skillFiles[] = $arg . '/SKILL.md';
        } elseif (is_file($arg)) {
            $skillFiles[] = $arg;
        } else {
            fwrite(STDERR, "warning: argument '{$arg}' is not a SKILL.md or skill directory; skipping\n");
        }
    }
}

if ($skillFiles === []) {
    fwrite(STDERR, "no SKILL.md files found to validate\n");
    exit(2);
}

$validator   = new ValidateSkillMd();
$totalErrors = 0;

foreach ($skillFiles as $file) {
    $name   = basename(dirname($file));
    $errors = $validator($file);

    if ($errors === []) {
        fwrite(STDOUT, "ok   {$name}\n");
        continue;
    }

    fwrite(STDOUT, "FAIL {$name}\n");
    foreach ($errors as $err) {
        fwrite(STDOUT, "       - {$err}\n");
        $totalErrors++;
    }
}

fwrite(STDOUT, "\n");
if ($totalErrors === 0) {
    fwrite(STDOUT, "All " . count($skillFiles) . " skill(s) conformant.\n");
    exit(0);
}

fwrite(STDOUT, "{$totalErrors} violation(s) across " . count($skillFiles) . " skill(s).\n");
exit(1);
