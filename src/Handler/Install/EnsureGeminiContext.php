<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\SelfSetEntry;
use JardisTools\DevSkills\Data\TextEdit;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Makes `.gemini/settings.json` list AGENTS.md in `context.fileName` (R7). A missing file is
 * created (an empty object that gets the entry like any other). The change is one text
 * insertion or replacement, so every other character of a foreign file stays. The exact
 * change and whether the plugin created the file go into the manifest, so uninstall can
 * reverse exactly this. A file that cannot be extended character for character, or is not valid
 * JSON, is left untouched and reported (the planner throws, the add-on decorator warns).
 * Never through a link: neither the file nor a folder on the way to it (`.gemini`) may be a link, whether it
 * leads out of the project or stays inside it; the check runs before anything is read, created or written.
 */
final class EnsureGeminiContext
{
    public const FILE = '.gemini/settings.json';

    private const NEW_FILE = "{\n}\n";

    /**
     * @param Closure(string): ?TextEdit                  $planEdit
     * @param Closure(string, string, SelfSetEntry): void $recordSelfSet
     * @param Closure(string, string): bool               $isPathBehindLink
     */
    public function __construct(
        private readonly Closure $planEdit,
        private readonly Closure $recordSelfSet,
        private readonly Closure $isPathBehindLink,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $target = $projectRoot . '/' . self::FILE;

        if (($this->isPathBehindLink)($projectRoot, $target)) {
            throw new InstallFailedException(sprintf(
                '"%s" is a link or lies behind one (a link that leads out of the project or stays inside it);'
                . ' nothing was written.',
                $target,
            ));
        }

        if (file_exists($target) && !is_file($target)) {
            throw new InstallFailedException(sprintf('"%s" exists but is not a regular file.', $target));
        }

        $created = !is_file($target);
        $content = $created ? self::NEW_FILE : file_get_contents($target);
        if ($content === false) {
            throw new InstallFailedException(sprintf('Could not read "%s".', $target));
        }

        $edit = ($this->planEdit)($content);
        if ($edit === null) {
            return;
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new InstallFailedException(sprintf('Could not create "%s".', $directory));
        }

        $updated = substr_replace($content, $edit->replacement, $edit->offset, $edit->length);
        if (@file_put_contents($target, $updated) === false) {
            throw new InstallFailedException(sprintf('Could not write "%s".', $target));
        }

        ($this->recordSelfSet)($projectRoot, self::FILE, new SelfSetEntry(
            $created,
            substr($content, $edit->offset, $edit->length),
            $edit->replacement,
        ));
    }
}
