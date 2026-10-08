<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * The single public source for the names of the bundle skills shipped from
 * 1.4.0 on: one entry per folder in `skills/`. The manifest-less uninstall
 * removes exactly these names (plus the old names of RenamedSkills), never a
 * prefix. A test pins this list to the folders of `skills/`.
 * Pure data.
 */
final class BundleSkills
{
    /** @var list<string> */
    public const NAMES = [
        'code-review-change',
        'design-draft-schema',
        'design-headless-mcp',
        'design-model-capabilities',
        'foundation-architecture',
        'foundation-frontend-review',
        'foundation-patterns',
        'foundation-php',
        'foundation-testing',
        'foundation-working-principles',
        'generated-code-extend',
        'generated-code-recipes',
        'generated-code-versioning',
        'generated-code-wire-transport',
        'generated-code-workflow-api',
        'git-check-compliance',
        'git-commit-change',
        'git-push-and-open-pr',
        'git-setup-repository',
        'git-start-branch',
        'knowledge-maintain-pool',
        'knowledge-record-decision',
        'packages-find-existing',
        'process-check-existing',
        'process-choose-tier',
        'process-close',
        'process-concept',
        'process-resume',
        'process-review-board',
        'process-run-stage',
        'process-verify',
        'process-write-plan',
        'process-write-prd',
        'start-orientation',
    ];
}
