<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\ExcludeFileLocation;

/**
 * Finds the exclude file of the Git repository whose work tree is the project root, through
 * `git rev-parse --git-path info/exclude`. That also holds in a linked work tree, where `.git`
 * is a file and only the `info/exclude` of the shared Git directory takes effect. A relative
 * answer is resolved against the project root. The location has no path when the project root is
 * not the top of a work tree: no repository or no Git, or a project below the top of a larger
 * repository, whose exclude patterns would be relative to another directory (that work tree is named).
 */
final class ResolveGitDir
{
    /**
     * @param Closure(string, list<string>): ?string $runGit
     */
    public function __construct(private readonly Closure $runGit)
    {
    }

    public function __invoke(string $projectRoot): ExcludeFileLocation
    {
        $topLevel = ($this->runGit)($projectRoot, ['rev-parse', '--show-toplevel']);
        $realRoot = realpath($projectRoot);
        if ($topLevel === null || $realRoot === false) {
            return new ExcludeFileLocation(null);
        }
        $topLevel = rtrim($topLevel, "\r\n");
        if (realpath($topLevel) !== $realRoot) {
            return new ExcludeFileLocation(null, $topLevel);
        }

        $path = ($this->runGit)($projectRoot, ['rev-parse', '--git-path', 'info/exclude']);
        $path = $path === null ? '' : rtrim($path, "\r\n");
        if ($path === '') {
            return new ExcludeFileLocation(null);
        }

        return new ExcludeFileLocation(
            str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
                ? $path
                : $realRoot . '/' . $path,
        );
    }
}
