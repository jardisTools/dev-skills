<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * What the plugin itself set in a foreign file outside the skill folders (CLAUDE.md, Gemini settings).
 * Pure data: whether the plugin created the file, and the exact text change it made
 * (`before` was replaced by `after`; an insertion has an empty `before`), so the change can be reversed.
 */
final class SelfSetEntry
{
    public function __construct(
        public readonly bool $fileCreated,
        public readonly string $before = '',
        public readonly string $after = '',
    ) {
    }
}
