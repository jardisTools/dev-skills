<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

/**
 * Collects the agent files the plugin left unchanged (not reviewer shells of the plugin) into ONE notice.
 * No file, no notice. More than {@see self::MAX_NAMES} names are cut: "a, b, c, d, e, +14 more".
 */
final class SummarizeLeftUnchanged
{
    public const MAX_NAMES = 5;

    /**
     * @param list<string> $paths project-relative paths of the files left as they were
     */
    public function __invoke(array $paths): ?string
    {
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return null;
        }

        $dirs = array_values(array_unique(array_map(static fn (string $p): string => dirname($p), $paths)));
        $names = array_map(static fn (string $p): string => basename($p), $paths);
        $shown = array_slice($names, 0, self::MAX_NAMES);
        $list = implode(', ', $shown);
        if (count($names) > self::MAX_NAMES) {
            $list .= sprintf(', +%d more', count($names) - self::MAX_NAMES);
        }

        return sprintf(
            '%d agent file(s) under %s are not reviewer shells of the plugin and were left unchanged (%s)',
            count($paths),
            implode(', ', $dirs),
            $list,
        );
    }
}
