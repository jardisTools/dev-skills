<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

/**
 * Tells whether a path is a link that does not stay inside the project: a dangling link, or one whose
 * real target lies outside the real project root. The plugin never writes through such a link.
 * A regular file, a missing path and a link that stays inside the project are not affected.
 */
final class IsLinkLeavingProject
{
    public function __invoke(string $projectRoot, string $path): bool
    {
        if (!is_link($path)) {
            return false;
        }

        $target = realpath($path);
        $root = realpath($projectRoot);

        return $target === false || $root === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR);
    }
}
