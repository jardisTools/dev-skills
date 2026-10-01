<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

/**
 * Byte-exact picture of a directory tree, for "nothing changed" assertions.
 */
final class TreeSnapshot
{
    /**
     * @return array<string, string> relative path => sha256 of the content ("dir" for directories)
     */
    public static function of(string $root): array
    {
        $snapshot = [];
        if (!is_dir($root)) {
            return $snapshot;
        }

        $base = rtrim($root, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($base) + 1);
            $snapshot[$relative] = $item->isDir() ? 'dir' : hash_file('sha256', $item->getPathname());
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Snapshot of the project's managed surface: skill folders, AGENTS.md and the backup folder.
     *
     * @return array<string, array<string, string>>
     */
    public static function ofProject(TempProject $project): array
    {
        return [
            '.claude' => self::of($project->path('.claude')),
            '.agents' => self::of($project->path('.agents')),
            'AGENTS.md' => is_file($project->path('AGENTS.md'))
                ? ['AGENTS.md' => (string) hash_file('sha256', $project->path('AGENTS.md'))]
                : [],
        ];
    }
}
