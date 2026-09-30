<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use JardisTools\DevSkills\Data\ReviewerSource;

/**
 * The body of every shell: one sentence that points at the installed source in the skill. The shell
 * carries no review instructions of its own, so the source stays the single place they live in.
 */
final class BuildShellBody
{
    public const INSTALLED_DIR = '.claude/skills/process-review-board/reviewers';

    public function __invoke(ReviewerSource $source): string
    {
        return sprintf(
            'Read `%s/%s.md` and follow it as your complete review instructions.',
            self::INSTALLED_DIR,
            $source->role,
        );
    }
}
