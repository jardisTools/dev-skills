<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

/**
 * Resolves a path given relative to the project root without ever following a link. `.` and `..` are
 * folded lexically; a path that leaves the root, runs through a link or does not exist resolves to null.
 * Returns the absolute path of an existing file or folder otherwise.
 */
final class ResolvePath
{
    public function __invoke(string $root, string $relative): ?string
    {
        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $current = rtrim($root, '/');
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            if (is_link($current) || !file_exists($current)) {
                return null;
            }
        }

        return $current;
    }
}
