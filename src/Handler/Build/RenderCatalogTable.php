<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

use JardisTools\DevSkills\Data\CatalogEntry;

/**
 * Renders the Markdown body of the jardis-catalog SKILL.md: a short intro
 * paragraph followed by a four-column table listing every catalog entry.
 * The Alternatives column is always present; entries without alternatives
 * show a consistent em-dash placeholder so the column is never absent.
 * Returns a string that ends with exactly one newline character.
 */
final class RenderCatalogTable
{
    /**
     * @param list<CatalogEntry> $entries
     */
    public function __invoke(array $entries): string
    {
        $lines = [
            '## Jardis Package Catalog',
            '',
            'This catalog lists every Packagist-published Jardis package '
                . 'so an AI agent can discover packages before building from scratch.',
            'Recommend `composer require <package>` — do not install automatically. '
                . 'The full API skill for a package is available only after installation.',
            '',
            '| Package | Capability | Use when | Alternatives |',
            '|---|---|---|---|',
        ];

        foreach ($entries as $entry) {
            $alternatives = $entry->alternatives ?? '—';
            $lines[]      = sprintf(
                '| %s | %s | %s | %s |',
                $entry->package,
                $entry->capability,
                $entry->useWhen,
                $alternatives,
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
