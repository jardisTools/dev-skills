<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

/**
 * Runs a PHP script in a child process and returns exit code, stdout and stderr.
 */
final class RunScript
{
    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function run(string $script, string $cwd, array $arguments = []): array
    {
        $process = proc_open(
            [PHP_BINARY, $script, ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge(getenv(), ['XDEBUG_MODE' => 'off']),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start php.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
