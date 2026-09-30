<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use Closure;
use JardisTools\DevSkills\Data\ReviewerSource;
use JardisTools\DevSkills\Data\ShellFormat;

/**
 * Reads a reviewer source file. A source is valid when the role (the file name without `.md`) is a
 * plain lowercase name, the frontmatter holds a single-line `name` equal to the role and a
 * single-line, non-empty `description`. Anything else yields null: no shell comes out of it.
 */
final class ParseReviewerSource
{
    /**
     * @param Closure(string): (array{fields: array<string, string|list<string>>, body: string}|null) $parseFrontmatter
     */
    public function __construct(private readonly Closure $parseFrontmatter)
    {
    }

    public function __invoke(string $role, string $content): ?ReviewerSource
    {
        if (preg_match('/^' . ShellFormat::ROLE_PATTERN . '$/D', $role) !== 1) {
            return null;
        }

        $parsed = ($this->parseFrontmatter)($content);
        if ($parsed === null) {
            return null;
        }

        $name = $parsed['fields']['name'] ?? null;
        $description = $parsed['fields']['description'] ?? null;
        if (
            $name !== $role
            || !is_string($description)
            || $description === ''
            || preg_match('/[\x00-\x1F\x7F]/', $description) === 1
        ) {
            return null;
        }

        return new ReviewerSource($role, $name, $description, trim($parsed['body']));
    }
}
