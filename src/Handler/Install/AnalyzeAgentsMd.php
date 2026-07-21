<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\AgentsMdAnalysis;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class AnalyzeAgentsMd
{
    public const HEADER = '<!-- BEGIN jardis/dev-skills — managed block, do not edit by hand -->';
    public const FOOTER = '<!-- END jardis/dev-skills -->';

    /**
     * Inspects the AGENTS.md file at $filePath and splits it into the regions
     * around the managed block. A missing file is reported as "not existed",
     * never thrown.
     *
     * A duplicated managed block (more than one BEGIN and/or END, e.g. an
     * earlier dev-skills version that appended instead of replacing) is
     * self-healed: the whole region from the FIRST BEGIN to the LAST END is
     * treated as the managed region and collapsed into a single block by the
     * orchestrator. Only genuinely ambiguous corruption still throws (BEGIN
     * without END, END without BEGIN, or first BEGIN after last END).
     *
     * Markers are recognised only when they stand alone on their own line —
     * an inline mention of the marker string inside a Markdown bullet or
     * code fence is treated as content, not as a marker. The plugin always
     * writes markers on their own line.
     */
    public function __invoke(string $filePath): AgentsMdAnalysis
    {
        if (!is_file($filePath)) {
            return new AgentsMdAnalysis(false, false, '', '');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new InstallFailedException(sprintf('Could not read AGENTS.md at "%s".', $filePath));
        }

        $headerMatches = $this->matchLine($content, self::HEADER);
        $footerMatches = $this->matchLine($content, self::FOOTER);
        $headerCount = count($headerMatches);
        $footerCount = count($footerMatches);

        if ($headerCount === 0 && $footerCount === 0) {
            return new AgentsMdAnalysis(true, false, $content, '');
        }

        if ($headerCount === 0 || $footerCount === 0) {
            throw new InstallFailedException(sprintf(
                'AGENTS.md at "%s" has corrupt managed-block markers '
                . '(need at least one BEGIN and one END on their own line, found %d/%d).',
                $filePath,
                $headerCount,
                $footerCount,
            ));
        }

        // Heal-region: everything from the FIRST BEGIN to the LAST END is the
        // tool-owned managed region ("do not edit by hand"). Any duplicate or
        // nested blocks in between are part of that region and get replaced by
        // a single fresh block downstream. User content lives OUTSIDE the
        // markers (in preBlock/postBlock) and is left untouched.
        $headerPos = $headerMatches[0];
        $footerPos = $footerMatches[$footerCount - 1];
        if ($headerPos > $footerPos) {
            throw new InstallFailedException(sprintf(
                'AGENTS.md at "%s" has managed-block markers in the wrong order.',
                $filePath,
            ));
        }

        $preBlock = substr($content, 0, $headerPos);
        $postBlock = substr($content, $footerPos + strlen(self::FOOTER));
        $healed = $headerCount > 1 || $footerCount > 1;

        return new AgentsMdAnalysis(true, true, $preBlock, $postBlock, $healed);
    }

    /**
     * @return list<int> Byte offsets of every line whose entire content equals $marker.
     */
    private function matchLine(string $content, string $marker): array
    {
        $pattern = '/^' . preg_quote($marker, '/') . '$/m';
        $matched = preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            return [];
        }

        return array_map(static fn (array $m): int => (int) $m[1], $matches[0]);
    }
}
