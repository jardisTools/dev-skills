<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Where the Git exclude file of a project is, or why there is none. Pure data: `path` is set when
 * the project root is the top of a Git work tree; otherwise `enclosingWorkTree` names the work tree
 * the project lies in (below its top), or is null when there is no repository at all.
 */
final readonly class ExcludeFileLocation
{
    public function __construct(
        public ?string $path,
        public ?string $enclosingWorkTree = null,
    ) {
    }
}
