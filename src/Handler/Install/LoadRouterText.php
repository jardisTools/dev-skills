<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Exception\InstallFailedException;

final class LoadRouterText
{
    public const RELATIVE_PATH = 'router/AGENTS-router.md';

    /**
     * Reads the router text that opens the managed AGENTS.md block from
     * <pluginRoot>/router/AGENTS-router.md. A missing file yields the empty
     * slot (no warning); line endings are normalised to LF and surrounding
     * blank lines are trimmed. An existing file that cannot be read is a core
     * error, because the router belongs to the managed block.
     */
    public function __invoke(string $pluginRoot): string
    {
        $path = $pluginRoot . '/' . self::RELATIVE_PATH;
        if (!is_file($path)) {
            return '';
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InstallFailedException(sprintf('Could not read router text at "%s".', $path));
        }

        return trim(str_replace("\r\n", "\n", $content), "\n");
    }
}
