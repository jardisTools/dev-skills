<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * One parsed file of the knowledge pool (a topic page or INDEX.md). Headings, links and inline code
 * are collected outside the frontmatter and outside fenced code blocks; lines are 1-based.
 */
final class PoolPage
{
    /** A page id: the file stem, also the target of a `[[id]]` link. */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * @param string                                 $file      path relative to the project root
     * @param string                                 $id        file stem
     * @param int                                    $bytes     size of the stored file
     * @param int                                    $lineCount number of lines of the stored file
     * @param list<string>                           $ersetzt   ids from the frontmatter list `ersetzt`
     * @param list<array{line: int, title: string}>  $headings  every level-2 heading
     * @param list<array{line: int, target: string}> $wikiLinks target of each `[[target]]` (label, anchor cut off)
     * @param list<array{line: int, target: string}> $mdLinks   target of each `[text](target)`
     * @param list<array{line: int, target: string}> $codeSpans content of each inline code span
     */
    public function __construct(
        public readonly string $file,
        public readonly string $id,
        public readonly int $bytes,
        public readonly int $lineCount,
        public readonly array $ersetzt,
        public readonly array $headings,
        public readonly array $wikiLinks,
        public readonly array $mdLinks,
        public readonly array $codeSpans,
    ) {
    }
}
