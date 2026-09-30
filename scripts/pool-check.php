#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Checks the knowledge pool of a project (`<root>/.claude/wissen/`): topic page layout, links, redirects,
 * path references and size caps; and the work folders (`<root>/docs/vorhaben/`): progress head and plan caps.
 * Reads only, never writes.
 *
 * Usage: php vendor/jardis/dev-skills/scripts/pool-check.php [--root=<dir>] [--help]
 *    or: vendor/bin/pool-check.php [--root=<dir>] [--help]
 *
 * --root=<dir>  project root; default is the working directory. Naming the root also lets the
 *               path references be checked without a `.git` entry in it.
 * --help        print the usage and exit 0.
 *
 * Exit code: 0 clean, 1 violations found, 2 usage error or no pool. Brings its own loader for
 * JardisTools\DevSkills\ because the autoloader of the project is not part of this plugin.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'JardisTools\\DevSkills\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use JardisTools\DevSkills\Exception\PoolNotFoundException;
use JardisTools\DevSkills\Handler\Pool\FormatReport;
use JardisTools\DevSkills\PoolCheck;

$usage = "usage: php pool-check.php [--root=<dir>] [--help]\n";
$help  = <<<'TEXT'
Checks the knowledge pool (.claude/wissen/) and the work folders (docs/vorhaben/) of a project.
Reads only, never writes.

usage: php pool-check.php [--root=<dir>] [--help]

  --root=<dir>  project root (default: the working directory)
  --help        print this text and exit 0

exit code: 0 clean, 1 violations found, 2 usage error or no pool

TEXT;

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, $help);
    exit(0);
}

$root = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--root=') && strlen($argument) > strlen('--root=')) {
        $root = substr($argument, strlen('--root='));
        continue;
    }
    fwrite(STDERR, $usage);
    exit(2);
}

$rootExplicit = $root !== null;
$root ??= getcwd();
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "project root is not a folder\n");
    exit(2);
}

try {
    $result = (new PoolCheck())($root, $rootExplicit);
} catch (PoolNotFoundException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

foreach ((new FormatReport())($result) as $line) {
    fwrite(STDOUT, $line . "\n");
}
exit($result->violations === [] ? 0 : 1);
