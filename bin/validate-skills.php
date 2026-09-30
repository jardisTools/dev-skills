<?php

declare(strict_types=1);

/**
 * Validates every bundled SKILL.md against the format rules in
 * docs/SKILL-FORMAT.md. Chains three checks, each a closure:
 *   format  ValidateSkillMd   frontmatter, zone/persona, budgets, body rules
 *   links   CheckSkillLinks   prerequisites / next resolve to skill folders
 *   rules   CheckRuleMarkers  rule markers and cap figures of the process skills
 *
 * Exits 0 when all skills are conformant; non-zero otherwise.
 *
 * Usage (typically via `make validate-skills`):
 *   php bin/validate-skills.php [<skill-dir> ...]
 *
 * With no arguments, scans skills/ in the repo root.
 */

require __DIR__ . '/../vendor/autoload.php';

use JardisTools\DevSkills\Handler\Validate\CheckRuleMarkers;
use JardisTools\DevSkills\Handler\Validate\CheckSkillLinks;
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

$perFileChecks = [
    'format' => new ValidateSkillMd(),
    'links'  => new CheckSkillLinks(),
];
$rulesCheck = new CheckRuleMarkers();

/** @var array<string, list<string>> $violations skill name => "[check] reason" */
$violations = [];
$skillNames = [];
$roots      = [];

foreach ($skillFiles as $file) {
    $name         = basename(dirname($file));
    $skillNames[] = $name;
    $roots[dirname($file, 2)] = true;
    $violations[$name] = [];

    foreach ($perFileChecks as $check => $run) {
        foreach ($run($file) as $err) {
            $violations[$name][] = "[{$check}] {$err}";
        }
    }
}

foreach (array_keys($roots) as $root) {
    foreach ($rulesCheck($root, $argList === [] ? null : $skillNames) as $name => $errors) {
        foreach ($errors as $err) {
            $violations[$name][] = "[rules] {$err}";
        }
    }
}

$totalErrors = 0;
foreach ($violations as $name => $errors) {
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
