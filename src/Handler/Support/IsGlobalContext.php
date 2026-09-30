<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

/**
 * Tells whether Composer runs in its global context (`composer global ...`):
 * there the working directory is the Composer home, not a project, and the
 * plugin must neither write nor delete anything.
 */
final class IsGlobalContext
{
    public function __invoke(string $workingDirectory, mixed $composerHome): bool
    {
        if (!is_string($composerHome) || $composerHome === '') {
            return false;
        }

        $cwd = realpath($workingDirectory);
        $home = realpath($composerHome);

        return $cwd !== false && $home !== false && $cwd === $home;
    }
}
