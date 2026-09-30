<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Closure;
use JardisTools\DevSkills\Data\AgentsMdAnalysis;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Exception\UninstallFailedException;
use JardisTools\DevSkills\Handler\Install\EnsureClaudeMdImport;

/**
 * Takes the plugin's import block out of the root CLAUDE.md. Text outside the block stays
 * byte for byte; if only white space is left, the file is deleted. A file without the block,
 * a `@AGENTS.md` line outside of one, and a link to AGENTS.md (its block belongs to the
 * AGENTS.md removal) or out of the project stay as they are. Corrupt markers: a warning, the file stays untouched.
 */
final class RemoveClaudeMdImport
{
    /**
     * @param Closure(string): AgentsMdAnalysis $analyze
     * @param Closure(string): string           $detectLineEnding
     * @param Closure(string, string, string): string $stripImport
     * @param Closure(string, string): bool           $isLinkLeavingProject
     */
    public function __construct(
        private readonly Closure $analyze,
        private readonly Closure $detectLineEnding,
        private readonly Closure $stripImport,
        private readonly Closure $isLinkLeavingProject,
    ) {
    }

    public function __invoke(string $projectRoot, ?Manifest $manifest, UninstallReport $report): void
    {
        $target = $projectRoot . '/' . EnsureClaudeMdImport::FILE;
        if (
            !is_file($target)
            || ($this->isLinkLeavingProject)($projectRoot, $target)
            || (is_link($target) && realpath($target) === realpath($projectRoot . '/AGENTS.md'))
        ) {
            return;
        }

        try {
            $analysis = ($this->analyze)($target);
        } catch (InstallFailedException) {
            $report->addWarningIfAny('CLAUDE.md has corrupt managed-block markers; the file was left untouched.');

            return;
        }
        if (!$analysis->hasManagedBlock) {
            return;
        }

        $eol = ($this->detectLineEnding)($analysis->preBlock . $analysis->postBlock);
        $remaining = ($this->stripImport)($analysis->preBlock, $analysis->postBlock, $eol);

        if (trim($remaining) === '') {
            if (!@unlink($target)) {
                throw new UninstallFailedException(sprintf('Could not delete CLAUDE.md at "%s".', $target));
            }

            return;
        }
        if (@file_put_contents($target, $remaining) === false) {
            throw new UninstallFailedException(sprintf('Could not strip the import block from "%s".', $target));
        }
    }
}
