<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\AgentsMdAnalysis;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Exception\UninstallFailedException;

/**
 * Takes the plugin's managed block out of the project's AGENTS.md (`agents-md=none`). Text outside the
 * block stays byte for byte. If nothing but white space is left and the manifest says the plugin created
 * the file itself, the file is deleted; any other file stays, even if it is empty then. A file without the
 * block stays as it is; corrupt markers give a warning and an untouched file. Never through a link.
 */
final class RemoveManagedAgentsMd
{
    public const FILE = 'AGENTS.md';

    /**
     * @param Closure(string): AgentsMdAnalysis $analyze
     * @param Closure(string, string): bool     $isPathBehindLink
     */
    public function __construct(
        private readonly Closure $analyze,
        private readonly Closure $isPathBehindLink,
    ) {
    }

    /**
     * @return ?string a warning, or null
     */
    public function __invoke(string $projectRoot, Manifest $manifest): ?string
    {
        $target = $projectRoot . '/' . self::FILE;
        if (($this->isPathBehindLink)($projectRoot, $target) || !is_file($target)) {
            return null;
        }

        try {
            $analysis = ($this->analyze)($target);
        } catch (InstallFailedException) {
            return 'AGENTS.md has corrupt managed-block markers; the file was left untouched (agents-md=none).';
        }
        if (!$analysis->hasManagedBlock) {
            return null;
        }

        $remaining = $analysis->preBlock . $analysis->postBlock;
        $createdByPlugin = ($manifest->selfSet[self::FILE] ?? null)?->fileCreated === true;

        if (trim($remaining) === '' && $createdByPlugin) {
            if (!@unlink($target)) {
                throw new UninstallFailedException(sprintf('Could not delete AGENTS.md at "%s".', $target));
            }

            return null;
        }
        if (@file_put_contents($target, $remaining) === false) {
            throw new UninstallFailedException(sprintf('Could not remove the managed block from "%s".', $target));
        }

        return null;
    }
}
