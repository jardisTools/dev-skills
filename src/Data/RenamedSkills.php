<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * The single public source for the bundle skill renaming: the 18 names shipped
 * up to 1.3.x (keys) and the names they carry from 1.4.0 on (values).
 * Pure data. Code that needs "the old names" uses the keys.
 */
final class RenamedSkills
{
    /** @var array<string, string> old name => new name */
    public const MAPPING = [
        'jardis-start-here' => 'start-orientation',
        'jardis-catalog' => 'packages-find-existing',
        'jardis-mcp-consumer' => 'design-headless-mcp',
        'schema-authoring' => 'design-draft-schema',
        'platform-implementation' => 'generated-code-extend',
        'platform-usage' => 'generated-code-wire-transport',
        'platform-versioning' => 'generated-code-versioning',
        'platform-workflow' => 'generated-code-workflow-api',
        'platform-cookbook' => 'generated-code-recipes',
        'rules-architecture' => 'foundation-architecture',
        'rules-patterns' => 'foundation-patterns',
        'rules-testing' => 'foundation-testing',
        'rules-frontend' => 'foundation-frontend-review',
        'do-project-git-setup' => 'git-setup-repository',
        'do-git-branch' => 'git-start-branch',
        'do-git-commit' => 'git-commit-change',
        'do-git-push' => 'git-push-and-open-pr',
        'do-git-compliance' => 'git-check-compliance',
    ];
}
