<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\PoolViolation;

/**
 * Checks the size cap of topic pages: at most 16384 bytes and at most 160 lines, both limits together,
 * measured on the stored file including the frontmatter. Reports only; it never splits or trims a page.
 */
final class CheckPageBudget
{
    public const MAX_BYTES = 16384;
    public const MAX_LINES = 160;

    /**
     * @param list<PoolPage> $pages topic pages (not INDEX.md)
     * @return list<PoolViolation>
     */
    public function __invoke(array $pages): array
    {
        $violations = [];
        foreach ($pages as $page) {
            if ($page->bytes > self::MAX_BYTES) {
                $violations[] = new PoolViolation(
                    $page->file,
                    1,
                    PoolViolation::RULE_PAGE_BYTES,
                    sprintf('page has %d bytes, the cap is %d', $page->bytes, self::MAX_BYTES),
                );
            }
            if ($page->lineCount > self::MAX_LINES) {
                $violations[] = new PoolViolation(
                    $page->file,
                    self::MAX_LINES + 1,
                    PoolViolation::RULE_PAGE_LINES,
                    sprintf('page has %d lines, the cap is %d', $page->lineCount, self::MAX_LINES),
                );
            }
        }

        return $violations;
    }
}
