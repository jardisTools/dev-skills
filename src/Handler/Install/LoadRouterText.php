<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Exception\InstallFailedException;

final class LoadRouterText
{
    public const RELATIVE_PATH = 'router/AGENTS-router.md';

    /** The area between the marker lines, the marker lines included, and the blank line after it. */
    private const GIT_RULES_AREA = '/^<!-- git-rules -->\n.*?^<!-- \/git-rules -->(?:\n\n?|$)/ms';

    /** A marker line of the area; never part of the text the session reads. */
    private const GIT_RULES_MARKER_LINE = '/^<!-- \/?git-rules -->(?:\n|$)/m';

    /**
     * Reads the router text that opens the managed AGENTS.md block from
     * <pluginRoot>/router/AGENTS-router.md. A missing file yields the empty
     * slot (no warning); line endings are normalised to LF and surrounding
     * blank lines are trimmed. An existing file that cannot be read is a core
     * error, because the router belongs to the managed block.
     *
     * The git rules stand between the marker lines `<!-- git-rules -->` and
     * `<!-- /git-rules -->`. The marker lines never reach the result; with
     * `$gitRules = false` the area between them is dropped as well. A router
     * without marker lines is returned unchanged either way.
     */
    public function __invoke(string $pluginRoot, bool $gitRules = true): string
    {
        $path = $pluginRoot . '/' . self::RELATIVE_PATH;
        if (!is_file($path)) {
            return '';
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InstallFailedException(sprintf('Could not read router text at "%s".', $path));
        }

        $text = str_replace("\r\n", "\n", $content);
        // A failed regex (`null`) or an area without its end marker leaves the text as it is: the git rules
        // stay in, so a defect can never remove them.
        if (!$gitRules) {
            $text = preg_replace(self::GIT_RULES_AREA, '', $text) ?? $text;
        }
        $text = preg_replace(self::GIT_RULES_MARKER_LINE, '', $text) ?? $text;

        return trim($text, "\n");
    }
}
