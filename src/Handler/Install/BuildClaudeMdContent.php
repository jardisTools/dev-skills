<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

/**
 * The CLAUDE.md text with the import block appended. The original text stays untouched up
 * front; a blank line separates it from the block, and the block lines use the file's line ending.
 */
final class BuildClaudeMdContent
{
    public const IMPORT_LINE = '@AGENTS.md';

    public function __invoke(string $existing, string $eol): string
    {
        $block = implode($eol, [AnalyzeAgentsMd::HEADER, self::IMPORT_LINE, AnalyzeAgentsMd::FOOTER]) . $eol;

        if ($existing === '') {
            return $block;
        }

        $completesLine = str_ends_with($existing, "\n") ? '' : $eol;

        return $existing . $completesLine . $eol . $block;
    }
}
