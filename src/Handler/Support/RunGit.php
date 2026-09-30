<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

/**
 * Runs one Git command in the project and returns what it printed, or null when it could not run
 * or did not succeed (no Git installed, no repository, a failing command). Always called with
 * `-c safe.directory=*`, so a project owned by another user (container, CI) is still readable.
 * The arguments go to Git as they are, without a shell. Read-only use only: the plugin never
 * changes the index or the history.
 */
final class RunGit
{
    /**
     * @param list<string> $arguments the Git arguments after the global options
     */
    public function __invoke(string $projectRoot, array $arguments): ?string
    {
        $process = @proc_open(
            ['git', '-c', 'safe.directory=*', '-C', $projectRoot, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $output !== false ? $output : null;
    }
}
