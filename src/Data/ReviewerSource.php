<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * One reviewer role as its source file `skills/process-review-board/reviewers/<role>.md` states it:
 * `name` and `description` from the frontmatter, the text below it as `body`. Pure data.
 */
final class ReviewerSource
{
    public function __construct(
        public readonly string $role,
        public readonly string $name,
        public readonly string $description,
        public readonly string $body,
    ) {
    }
}
