<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\VorhabenFile;
use JardisTools\DevSkills\Handler\Pool\LoadPool;
use JardisTools\DevSkills\Handler\Pool\LoadVorhaben;

/**
 * Puts a fixture case of tests/Fixture/Pool/<case>/ into a temp project: `pool/` becomes
 * `.claude/wissen/`, everything in `repo/` lands at the project root.
 */
final class PoolFixture
{
    public static function install(TempProject $project, string $case): void
    {
        $base = dirname(__DIR__) . '/Fixture/Pool/' . $case;
        self::copyTree($base . '/pool', $project->path('.claude/wissen'));
        if (is_dir($base . '/repo')) {
            self::copyTree($base . '/repo', $project->root);
        }
    }

    /**
     * @return array{index: PoolPage|null, pages: list<PoolPage>, files: list<PoolPage>}
     */
    public static function load(TempProject $project, string $case): array
    {
        self::install($project, $case);

        return (new LoadPool())($project->root);
    }

    /**
     * Installs a case and loads the files of its work folders, only those of one folder when one is named.
     *
     * @return list<VorhabenFile>
     */
    public static function loadVorhaben(TempProject $project, string $case, ?string $folder = null): array
    {
        self::install($project, $case);

        return array_values(array_filter(
            (new LoadVorhaben())($project->root),
            static fn (VorhabenFile $file): bool => $folder === null
                || str_starts_with($file->file, 'docs/vorhaben/' . $folder . '/'),
        ));
    }

    /**
     * Line number of the first line that contains the needle.
     */
    public static function lineOf(TempProject $project, string $relativeFile, string $needle): int
    {
        foreach (explode("\n", (string) file_get_contents($project->path($relativeFile))) as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i + 1;
            }
        }

        throw new \LogicException(sprintf('%s not found in %s', $needle, $relativeFile));
    }

    public static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0o755, true) && !is_dir($to)) {
            throw new \RuntimeException('Could not create ' . $to);
        }
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($from . '/' . $name)
                ? self::copyTree($from . '/' . $name, $to . '/' . $name)
                : copy($from . '/' . $name, $to . '/' . $name);
        }
    }
}
