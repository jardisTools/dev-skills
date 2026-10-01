<?php

declare(strict_types=1);

/**
 * Fails when the topmost version heading of CHANGELOG.md is not the version about to be tagged.
 * The date of the heading is not checked.
 *
 * Usage (typically via `make check-changelog-top VERSION=1.4.0`): php bin/check-changelog-top.php <version>
 *
 * Exit code: 0 the top is the given version, 1 it is not (or CHANGELOG.md is unreadable), 2 no version given.
 */

require __DIR__ . '/../vendor/autoload.php';

use JardisTools\DevSkills\Handler\Build\CheckChangelogTop;

$version = trim($argv[1] ?? '');
if ($version === '') {
    fwrite(STDERR, "usage: php bin/check-changelog-top.php <version>\n");
    exit(2);
}

$content = @file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
if ($content === false) {
    fwrite(STDERR, "CHANGELOG.md is not readable.\n");
    exit(1);
}

$error = (new CheckChangelogTop())($content, $version);
if ($error !== null) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

fwrite(STDOUT, "Changelog top is [{$version}].\n");
exit(0);
