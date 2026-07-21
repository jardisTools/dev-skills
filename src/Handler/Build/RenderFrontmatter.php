<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

/**
 * Renders the fixed YAML frontmatter block for the jardis-catalog SKILL.md.
 * Returns a string that ends with exactly one newline character.
 */
final class RenderFrontmatter
{
    /**
     * Canonical description for the jardis-catalog discovery skill.
     * Carries the E11 "reusable building block" trigger, ≥2 concrete
     * sub-triggers (infrastructure packages + DDD scaffolding), and the
     * L-D recommend-only semantics. Exactly ONE sentence (SKILL-FORMAT §2),
     * kept under 175 words.
     */
    private const DESCRIPTION =
        'Before hand-building any reusable building block in a Jardis project'
        . ' — caching, scheduling, HTTP clients, queues or messaging, validation,'
        . ' logging, secrets, persistence, or DDD scaffolding such as a context'
        . ' registry or workflow engine — consult this catalog to check whether an'
        . ' installable Jardis package already provides it and recommend'
        . ' `composer require <package>` instead of writing it yourself, never for'
        . ' project-specific business logic.';

    public function __invoke(): string
    {
        return implode("\n", [
            '---',
            'name: jardis-catalog',
            'zone: discovery',
            'persona: X',
            'prerequisites: []',
            'next: []',
            'description: ' . self::DESCRIPTION,
            '---',
        ]) . "\n";
    }
}
