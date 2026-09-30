<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\PoolViolation;

/**
 * Checks the size of INDEX.md: it stays under 10240 bytes, so exactly 10240 bytes already fails.
 * A pool without INDEX.md has nothing to measure.
 */
final class CheckIndexBudget
{
    public const LIMIT_BYTES = 10240;

    /**
     * @return list<PoolViolation>
     */
    public function __invoke(?PoolPage $index): array
    {
        if ($index === null || $index->bytes < self::LIMIT_BYTES) {
            return [];
        }

        return [new PoolViolation(
            $index->file,
            1,
            PoolViolation::RULE_INDEX_BYTES,
            sprintf('index has %d bytes, it must stay under %d', $index->bytes, self::LIMIT_BYTES),
        )];
    }
}
