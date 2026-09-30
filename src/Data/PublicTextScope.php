<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Set of repo-relative paths a public-text check has to read: `$paths` get
 * every check (path regex and denylist terms), `$regexOnlyPaths` only the
 * path regex.
 */
final class PublicTextScope
{
    /**
     * @param list<string> $paths
     * @param list<string> $regexOnlyPaths
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $regexOnlyPaths = [],
    ) {
    }
}
