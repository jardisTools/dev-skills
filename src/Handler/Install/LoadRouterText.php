<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\GitRulesMode;
use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class LoadRouterText
{
    public const RELATIVE_PATH = 'router/AGENTS-router.md';

    /** The strict area between its marker lines, the marker lines included, and the blank line after it. */
    private const STRICT_AREA = '/^<!-- git-rules -->\n.*?^<!-- \/git-rules -->(?:\n\n?|$)/ms';

    /** The delegated area between its marker lines, the marker lines included, and the blank line after it. */
    private const DELEGATED_AREA = '/^<!-- git-rules:delegated -->\n.*?^<!-- \/git-rules:delegated -->(?:\n\n?|$)/ms';

    /** The area of the profile `jardis`, marker lines included, and the blank line after it. */
    private const JARDIS_AREA = '/^<!-- profile:jardis -->\n.*?^<!-- \/profile:jardis -->(?:\n\n?|$)/ms';

    /** The area of the profile `core`, marker lines included, and the blank line after it. */
    private const CORE_AREA = '/^<!-- profile:core -->\n.*?^<!-- \/profile:core -->(?:\n\n?|$)/ms';

    /** A marker line of any area; never part of the text the session reads. */
    private const MARKER_LINE = '/^<!-- \/?(?:git-rules(?::delegated)?|profile:(?:core|jardis)) -->(?:\n|$)/m';

    /**
     * Reads the router text that opens the managed AGENTS.md block from
     * <pluginRoot>/router/AGENTS-router.md. A missing file yields the empty
     * slot (no warning); line endings are normalised to LF and surrounding
     * blank lines are trimmed. An existing file that cannot be read is a core
     * error, because the router belongs to the managed block.
     *
     * The git rules stand in two areas: the strict one between the marker
     * lines `<!-- git-rules -->` and `<!-- /git-rules -->`, the delegated one
     * between `<!-- git-rules:delegated -->` and `<!-- /git-rules:delegated -->`.
     * Exactly one area stays: strict keeps the first, delegated the second,
     * off none. The profile areas work the same way, between `<!-- profile:jardis -->` and
     * `<!-- /profile:jardis -->` resp. `<!-- profile:core -->` and `<!-- /profile:core -->`: the area of
     * the other profile is removed. The marker lines never reach the result. A router without
     * marker lines is returned unchanged in every stance and profile.
     */
    public function __invoke(
        string $pluginRoot,
        GitRulesMode $gitRules = GitRulesMode::Strict,
        InstallProfile $profile = InstallProfile::Jardis,
    ): string {
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
        if ($gitRules !== GitRulesMode::Strict) {
            $text = preg_replace(self::STRICT_AREA, '', $text) ?? $text;
        }
        if ($gitRules !== GitRulesMode::Delegated) {
            $text = preg_replace(self::DELEGATED_AREA, '', $text) ?? $text;
        }
        // Same fail-safe as above: a defect leaves both areas in rather than removing text.
        $foreign = $profile === InstallProfile::Core ? self::JARDIS_AREA : self::CORE_AREA;
        $text = preg_replace($foreign, '', $text) ?? $text;
        $text = preg_replace(self::MARKER_LINE, '', $text) ?? $text;

        return trim($text, "\n");
    }
}
