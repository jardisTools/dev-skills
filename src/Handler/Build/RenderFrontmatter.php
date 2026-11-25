<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

/**
 * Renders the fixed YAML frontmatter block for the packages-find-existing SKILL.md.
 * Returns a string that ends with exactly one newline character.
 */
final class RenderFrontmatter
{
    /**
     * Canonical description for the packages-find-existing discovery skill.
     * Carries the E11 "reusable building block" trigger, ≥2 concrete
     * sub-triggers (infrastructure packages + DDD scaffolding), and the
     * L-D recommend-only semantics. Exactly ONE sentence (SKILL-FORMAT §2),
     * kept under 175 words.
     */
    private const DESCRIPTION =
        'Catalog of installable Jardis packages — caching, scheduling, HTTP clients,'
        . ' queues or messaging, validation, logging, secrets, persistence, DDD scaffolding'
        . ' such as a workflow engine — to consult before hand-building any reusable'
        . ' building block in a PHP project, recommending `composer require <package>`'
        . ' instead, never for project-specific business logic.';

    public function __invoke(): string
    {
        return implode("\n", [
            '---',
            'name: packages-find-existing',
            'zone: discovery',
            'persona: X',
            'profile: core',
            'prerequisites: []',
            'next: []',
            'description: ' . self::DESCRIPTION,
            '---',
        ]) . "\n";
    }
}
