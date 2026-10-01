<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;

/**
 * Which of the given paths Git already tracks (`git ls-files`). A tracked file is not hidden by
 * an exclude entry. Read-only: the plugin never untracks anything. Git failing counts as nothing tracked.
 */
final class ListTrackedPaths
{
    /**
     * @param Closure(string, list<string>): ?string $runGit
     */
    public function __construct(private readonly Closure $runGit)
    {
    }

    /**
     * @param list<string> $paths project-relative paths; a leading slash is ignored
     * @return list<string>
     */
    public function __invoke(string $projectRoot, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $output = ($this->runGit)($projectRoot, [
            '--literal-pathspecs',
            'ls-files',
            '-z',
            '--',
            ...array_map(static fn (string $path): string => ltrim($path, '/'), $paths),
        ]);

        if ($output === null) {
            return [];
        }

        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    }
}
