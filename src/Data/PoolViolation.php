<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * One finding of the pool check. Carries the page (relative to the project root), the line, the rule
 * and a message that names only what the page itself says.
 */
final class PoolViolation
{
    public const RULE_SECTION_MISSING = 'section-missing';
    public const RULE_SECTION_FOREIGN = 'section-foreign';
    public const RULE_SECTION_ORDER   = 'section-order';
    public const RULE_LINK_DEAD       = 'link-dead';
    public const RULE_EDGE_DEAD       = 'edge-dead';
    public const RULE_PATH_DEAD       = 'path-dead';
    public const RULE_LINE_DEAD       = 'line-dead';
    public const RULE_PAGE_BYTES      = 'page-bytes';
    public const RULE_PAGE_LINES      = 'page-lines';
    public const RULE_INDEX_BYTES     = 'index-bytes';
    public const RULE_HEAD_MISSING    = 'head-missing';
    public const RULE_HEAD_POSITION   = 'head-position';
    public const RULE_HEAD_KEY        = 'head-key';
    public const RULE_HEAD_PHASE      = 'head-phase';
    public const RULE_HEAD_STAGE      = 'head-stage';
    public const RULE_HEAD_DECISIONS  = 'head-decisions';
    public const RULE_PROGRESS_LINES  = 'progress-lines';
    public const RULE_PLAN_LINES      = 'plan-lines';
    public const RULE_PLAN_SECTION    = 'plan-section-lines';
    public const RULE_PLAN_BYTES      = 'plan-bytes';

    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $rule,
        public readonly string $message,
    ) {
    }
}
