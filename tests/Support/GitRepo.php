<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

/**
 * Real Git repositories in temp directories, for the tests that must see what Git itself does
 * with an exclude entry. Git runs with `-c safe.directory=*` and a fixed identity, never with the
 * user's global configuration.
 */
final class GitRepo
{
    public static function init(string $directory): void
    {
        self::run($directory, 'init', '-q');
    }

    public static function commitAll(string $directory, string $message = 'test'): void
    {
        self::run($directory, 'add', '-A');
        self::run($directory, 'commit', '-q', '--allow-empty', '-m', $message);
    }

    public static function run(string $directory, string ...$arguments): string
    {
        $process = proc_open(
            [
                'git', '-c', 'safe.directory=*', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid',
                '-c', 'commit.gpgsign=false', '-C', $directory, ...$arguments,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start git.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . $error);
        }

        return $output;
    }

    /**
     * What `git status` reports as untracked or changed, one path per entry.
     *
     * @return list<string>
     */
    public static function visiblePaths(string $directory): array
    {
        $lines = array_filter(explode("\n", self::run($directory, 'status', '--porcelain', '--untracked-files=all')));

        return array_values(array_map(static fn (string $line): string => substr($line, 3), $lines));
    }
}
