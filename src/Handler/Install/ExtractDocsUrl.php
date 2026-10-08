<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

/**
 * Returns the first `https://` URL under the `## Full reference` heading of a package AGENTS.md, null when
 * the heading or the URL is missing.
 */
final class ExtractDocsUrl
{
    public function __invoke(string $content): ?string
    {
        $inSection = false;
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (str_starts_with($line, '## ')) {
                $inSection = strcasecmp(trim(substr($line, 3)), 'Full reference') === 0;
                continue;
            }
            if ($inSection && preg_match('#https://[^\s<>)\]]+#', $line, $match) === 1) {
                return rtrim($match[0], '.,;');
            }
        }

        return null;
    }
}
