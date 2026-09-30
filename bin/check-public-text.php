<?php

declare(strict_types=1);

/**
 * Fails when a publicly shipped text carries a local home path or a term from
 * the private denylist.
 *
 * Env PUBLIC_TEXT_DENYLIST         one term per line (CI secret); never printed.
 * Env PUBLIC_TEXT_REQUIRE_DENYLIST "1" makes an empty denylist an error.
 *
 * Usage (typically via `make check-public-text`): php bin/check-public-text.php
 */

require __DIR__ . '/../vendor/autoload.php';

use JardisTools\DevSkills\Handler\Build\ResolvePublicTextScope;
use JardisTools\DevSkills\Handler\Build\RunPublicTextGate;

$repoRoot = dirname(__DIR__);

try {
    $scope  = (new ResolvePublicTextScope())($repoRoot);
    $result = (new RunPublicTextGate())(
        $repoRoot,
        $scope,
        (string) getenv('PUBLIC_TEXT_DENYLIST'),
        getenv('PUBLIC_TEXT_REQUIRE_DENYLIST') === '1',
    );
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($result->error !== null) {
    fwrite(STDERR, $result->error . "\n");
    exit(1);
}
if ($result->regexOnly) {
    fwrite(STDOUT, "note: PUBLIC_TEXT_DENYLIST empty, checking home-path regex only.\n");
}
if ($result->violations === []) {
    fwrite(STDOUT, "Public text clean ({$result->scanned} file(s) scanned).\n");
    exit(0);
}

foreach ($result->violations as $violation) {
    fwrite(STDOUT, "{$violation->file}:{$violation->line} {$violation->kind}\n");
}
fwrite(STDOUT, count($result->violations) . " violation(s) in public text.\n");
exit(1);
