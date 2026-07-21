<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\AgentsDescriptor;

final class BuildManagedBlock
{
    /**
     * Thin pointer sentence placed inside the managed block when jardis-catalog
     * is installed. Points the agent at the catalog skill before any Eigenbau,
     * and at `jardis-start-here` as the master entry point for the full
     * lifecycle (schema -> design -> implementation).
     */
    public const CATALOG_POINTER
        = 'Before hand-building a reusable building block, '
        . 'consult the `jardis-catalog` skill to check for an installable Jardis package. '
        . 'For the full workflow from schema to implementation, start with the `jardis-start-here` skill.';

    /**
     * Builds the managed-block string (header + intro + optional catalog pointer
     * + sources + footer) that the plugin owns inside AGENTS.md. The returned
     * string does NOT end with a trailing newline — the caller decides how to
     * splice it into the file.
     *
     * @param list<AgentsDescriptor> $descriptors
     */
    public function __invoke(array $descriptors, bool $catalogInstalled = false): string
    {
        $sections = [
            AnalyzeAgentsMd::HEADER,
            '',
            '# Jardis packages — AI agent context',
            '',
            'Aggregated by `jardis/dev-skills`. Run `composer install` to refresh.',
            '',
        ];

        if ($catalogInstalled) {
            $sections[] = self::CATALOG_POINTER;
            $sections[] = '';
        }

        foreach ($descriptors as $descriptor) {
            $sections[] = '<!-- source: ' . $descriptor->sourcePackage . ' -->';
            $sections[] = rtrim($descriptor->content);
            $sections[] = '';
        }

        $sections[] = AnalyzeAgentsMd::FOOTER;

        return implode("\n", $sections);
    }
}
