<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

use JardisTools\DevSkills\Data\PublicTextScope;

/**
 * Determines which files ship publicly: every tracked file that is not
 * marked `export-ignore`, plus the published docs (the overview page, its
 * landing page and the skill format). Tracked files under
 * tests/Fixture/ are returned as regex-only paths. Paths that do not exist
 * on disk are skipped. An empty result (no tracked files, no scannable path)
 * or a failing git call throws: the gate must never pass on an empty scope.
 */
final class ResolvePublicTextScope
{
    private const EXTRA_PATHS = ['docs/overview.html', 'docs/index.html', 'docs/SKILL-FORMAT.md'];
    private const FIXTURE_PREFIX = 'tests/Fixture/';

    public function __invoke(string $repoRoot): PublicTextScope
    {
        $tracked = $this->git($repoRoot, ['ls-files', '-z'], null);
        $files   = $tracked === '' ? [] : explode("\0", rtrim($tracked, "\0"));
        if ($files === []) {
            throw new \RuntimeException('git ls-files returned no tracked files; public-text scope would be empty.');
        }

        $ignored = $this->exportIgnored($repoRoot, $files);

        $paths    = [];
        $fixtures = [];
        foreach ($files as $file) {
            if (!is_file($repoRoot . '/' . $file)) {
                continue;
            }
            if (str_starts_with($file, self::FIXTURE_PREFIX)) {
                $fixtures[] = $file;
                continue;
            }
            if (!$this->isIgnored($file, $ignored)) {
                $paths[] = $file;
            }
        }

        foreach (self::EXTRA_PATHS as $extra) {
            if (is_file($repoRoot . '/' . $extra) && !in_array($extra, $paths, true)) {
                $paths[] = $extra;
            }
        }

        if ($paths === []) {
            throw new \RuntimeException('Public-text scope is empty; refusing to pass without scanning anything.');
        }

        sort($paths);
        sort($fixtures);

        return new PublicTextScope($paths, $fixtures);
    }

    /**
     * A file is export-ignored when it or any ancestor directory is
     * (`git archive` prunes whole directories; check-attr does not inherit).
     *
     * @param array<string, true> $ignored
     */
    private function isIgnored(string $file, array $ignored): bool
    {
        for ($path = $file; $path !== '.' && $path !== ''; $path = dirname($path)) {
            if (isset($ignored[$path])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $files
     * @return array<string, true>
     */
    private function exportIgnored(string $repoRoot, array $files): array
    {
        if ($files === []) {
            return [];
        }

        $candidates = [];
        foreach ($files as $file) {
            for ($path = $file; $path !== '.' && $path !== ''; $path = dirname($path)) {
                $candidates[$path] = true;
            }
        }

        $out = $this->git(
            $repoRoot,
            ['check-attr', '-z', '--stdin', 'export-ignore'],
            implode("\0", array_keys($candidates)) . "\0",
        );
        $parts = explode("\0", $out);

        $ignored = [];
        for ($i = 0; $i + 2 < count($parts); $i += 3) {
            if ($parts[$i + 2] === 'set') {
                $ignored[$parts[$i]] = true;
            }
        }

        return $ignored;
    }

    /**
     * @param list<string> $args
     */
    private function git(string $repoRoot, array $args, ?string $stdin): string
    {
        $inputFile = null;
        $stdinSpec = ['file', '/dev/null', 'r'];
        if ($stdin !== null) {
            // Input goes through a file, so a large path list cannot deadlock against the output pipe.
            $inputFile = tempnam(sys_get_temp_dir(), 'pubtext-');
            if ($inputFile === false || file_put_contents($inputFile, $stdin) === false) {
                throw new \RuntimeException('Cannot write git input file.');
            }
            $stdinSpec = ['file', $inputFile, 'r'];
        }

        try {
            $proc = proc_open(
                array_merge(['git', '-c', 'safe.directory=*', '-C', $repoRoot], $args),
                [0 => $stdinSpec, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($proc)) {
                throw new \RuntimeException('Cannot start git.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
        } finally {
            if ($inputFile !== null) {
                unlink($inputFile);
            }
        }

        if ($code !== 0 || $stdout === false) {
            throw new \RuntimeException(sprintf('git %s failed: %s', $args[0], trim((string) $stderr)));
        }

        return $stdout;
    }
}
