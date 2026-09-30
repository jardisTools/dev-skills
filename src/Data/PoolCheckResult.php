<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Outcome of one pool check run: every violation and the number of topic pages that were checked.
 */
final class PoolCheckResult
{
    /**
     * @param list<PoolViolation> $violations
     */
    public function __construct(
        public readonly array $violations,
        public readonly int $pages,
    ) {
    }
}
