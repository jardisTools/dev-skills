<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

/**
 * Tells whether a path lies behind a link, so the plugin never writes, creates or deletes through it.
 * True when the path itself is a link (a dangling one too), when any folder between the project root
 * (excluded) and the path is a link, when the path holds a `..` segment (a link before it cannot be told
 * apart), or when the nearest existing ancestor does not lie inside the real project root. The walk uses
 * `is_link` segment by segment; `realpath` alone would follow the link and miss it. A link that stays
 * inside the project counts like one that leads out of it. A regular file and a missing path without a
 * link on the way are not affected.
 */
final class IsPathBehindLink
{
    public function __invoke(string $projectRoot, string $path): bool
    {
        $realRoot = realpath($projectRoot);
        if ($realRoot === false || is_link($path)) {
            return true;
        }

        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        if (str_starts_with($path, $root . DIRECTORY_SEPARATOR) && $this->hasLinkBelow($root, $path)) {
            return true;
        }

        return $this->leavesRoot($path, $realRoot);
    }

    private function hasLinkBelow(string $root, string $path): bool
    {
        $current = $root;
        foreach (explode(DIRECTORY_SEPARATOR, substr($path, strlen($root) + 1)) as $segment) {
            if ($segment === '..') {
                return true;
            }
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                return true;
            }
            if (!file_exists($current)) {
                return false;
            }
        }

        return false;
    }

    private function leavesRoot(string $path, string $realRoot): bool
    {
        $probe = $path;
        while (!file_exists($probe) && !is_link($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }

        $real = realpath($probe);

        return $real === false
            || ($real !== $realRoot && !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR));
    }
}
