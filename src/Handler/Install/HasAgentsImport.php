<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

/**
 * Tells whether a text already imports AGENTS.md on a line of its own (`@AGENTS.md`).
 */
final class HasAgentsImport
{
    public function __invoke(string $content): bool
    {
        return preg_match('/^@AGENTS\.md[ \t]*\r?$/m', $content) === 1;
    }
}
