<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\PoolViolation;

/**
 * Checks path references in inline code: a token like `dir/file.ext`, `dir/file.ext:12`, `dir/file.ext:12-20`
 * or `dir/` must exist relative to the project root, and a line number must not lie behind the end of the
 * file. A token without `/`, without a file extension (or trailing `/`) or with placeholders is not a path
 * reference. Runs only when the project root is known: passed explicitly, or a `.git` entry in it. No link is
 * followed.
 */
final class CheckPathRefs
{
    private const PATH_REF = '~^(?<path>(?:\./)?[A-Za-z0-9_][A-Za-z0-9_.\-]*(?:/[A-Za-z0-9_.\-]+)*'
        . '(?:/|\.[A-Za-z][A-Za-z0-9]{0,9}))(?::(?<from>\d+)(?:-(?<to>\d+))?)?$~';

    public function __construct(private readonly ResolvePath $resolvePath = new ResolvePath())
    {
    }

    /**
     * @param list<PoolPage> $files every pool file, INDEX.md included
     * @return list<PoolViolation>
     */
    public function __invoke(string $root, bool $rootExplicit, array $files): array
    {
        if (!$rootExplicit && (is_link($root . '/.git') || !file_exists($root . '/.git'))) {
            return [];
        }

        $violations = [];
        foreach ($files as $page) {
            foreach ($page->codeSpans as $span) {
                if (preg_match(self::PATH_REF, $span['target'], $ref) !== 1 || !str_contains($ref['path'], '/')) {
                    continue;
                }
                $violation = $this->check($root, $page->file, $span['line'], $ref);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<int|string, string> $ref
     */
    private function check(string $root, string $file, int $line, array $ref): ?PoolViolation
    {
        $resolved = ($this->resolvePath)($root, $ref['path']);
        if ($resolved === null) {
            return new PoolViolation(
                $file,
                $line,
                PoolViolation::RULE_PATH_DEAD,
                sprintf("path '%s' does not exist in the project", $ref['path']),
            );
        }

        $last = (int) ($ref['to'] ?? $ref['from'] ?? 0);
        if ($last > 0 && (!is_file($resolved) || $this->lineCount($resolved) < $last)) {
            return new PoolViolation(
                $file,
                $line,
                PoolViolation::RULE_LINE_DEAD,
                sprintf("'%s' has no line %d", $ref['path'], $last),
            );
        }

        return null;
    }

    private function lineCount(string $path): int
    {
        $count = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        $endsWithNewline = true;
        while (($chunk = fread($handle, 65536)) !== false && $chunk !== '') {
            $count += substr_count($chunk, "\n");
            $endsWithNewline = str_ends_with($chunk, "\n");
        }
        fclose($handle);

        return $endsWithNewline ? $count : $count + 1;
    }
}
