<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\AgentsDescriptor;

final class BuildManagedBlock
{
    /**
     * Builds the managed-block string (header + intro + sources + footer) that
     * the plugin owns inside AGENTS.md. The returned string does NOT end with
     * a trailing newline — the caller decides how to splice it into the file.
     *
     * @param list<AgentsDescriptor> $descriptors
     */
    public function __invoke(array $descriptors): string
    {
        $sections = [
            AnalyzeAgentsMd::HEADER,
            '',
            '# Jardis packages — AI agent context',
            '',
            'Aggregated by `jardis/dev-skills`. Run `composer install` to refresh.',
            '',
        ];

        foreach ($descriptors as $descriptor) {
            $sections[] = '<!-- source: ' . $descriptor->sourcePackage . ' -->';
            $sections[] = rtrim($descriptor->content);
            $sections[] = '';
        }

        $sections[] = AnalyzeAgentsMd::FOOTER;

        return implode("\n", $sections);
    }
}
