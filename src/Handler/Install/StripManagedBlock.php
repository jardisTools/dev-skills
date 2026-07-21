<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

final class StripManagedBlock
{
    /**
     * Removes a jardis/dev-skills managed block from the given AGENTS.md content
     * so that an aggregated source package never carries its own block into the
     * target's block (which would nest BEGIN/END markers).
     *
     * The managed region is everything from the FIRST BEGIN marker to the LAST
     * END marker (markers recognised only when alone on their own line). Only
     * that region — including the markers themselves — is removed; package-owned
     * content outside the markers (pre/post) is preserved.
     *
     * Lenient by design (unlike AnalyzeAgentsMd, which throws): a missing,
     * incomplete (only BEGIN or only END) or wrongly-ordered marker pair leaves
     * the content untouched. A foreign source AGENTS.md must never break the
     * install.
     */
    public function __invoke(string $content): string
    {
        $headerMatches = $this->matchLine($content, AnalyzeAgentsMd::HEADER);
        $footerMatches = $this->matchLine($content, AnalyzeAgentsMd::FOOTER);

        if ($headerMatches === [] || $footerMatches === []) {
            return $content;
        }

        $headerPos = $headerMatches[0];
        $footerPos = $footerMatches[count($footerMatches) - 1];
        if ($headerPos > $footerPos) {
            return $content;
        }

        $preBlock = substr($content, 0, $headerPos);
        $postBlock = substr($content, $footerPos + strlen(AnalyzeAgentsMd::FOOTER));

        return $preBlock . $postBlock;
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
