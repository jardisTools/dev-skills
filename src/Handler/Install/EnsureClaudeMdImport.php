<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\AgentsMdAnalysis;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\SelfSetEntry;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Makes the root CLAUDE.md import AGENTS.md through a marked block (R6). Only the root file
 * counts; `.claude/CLAUDE.md` is neither looked at nor changed.
 *
 * - CLAUDE.md missing: created with the block;
 * - CLAUDE.md without the import: the block is appended, the rest stays byte for byte;
 * - a block is there, or `@AGENTS.md` stands on its own line outside of one: nothing changes;
 * - corrupt markers, a link that leads to AGENTS.md itself (writing the block would put it into
 *   AGENTS.md) or out of the project: a warning, the file stays untouched.
 *
 * Anything else that goes wrong (a directory in place of the file, a write error) throws;
 * the add-on decorator turns that into a warning.
 */
final class EnsureClaudeMdImport
{
    public const FILE = 'CLAUDE.md';

    /**
     * @param Closure(string): AgentsMdAnalysis $analyze
     * @param Closure(string): bool             $hasAgentsImport
     * @param Closure(string): string           $detectLineEnding
     * @param Closure(string, string): string   $buildContent
     * @param Closure(string, string, SelfSetEntry): void $recordSelfSet
     * @param Closure(string, string): bool   $isLinkLeavingProject
     */
    public function __construct(
        private readonly Closure $analyze,
        private readonly Closure $hasAgentsImport,
        private readonly Closure $detectLineEnding,
        private readonly Closure $buildContent,
        private readonly Closure $recordSelfSet,
        private readonly Closure $isLinkLeavingProject,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $target = $projectRoot . '/' . self::FILE;

        if (file_exists($target) && !is_file($target)) {
            throw new InstallFailedException(sprintf('"%s" exists but is not a regular file.', $target));
        }
        if (is_link($target) && realpath($target) === realpath($projectRoot . '/AGENTS.md')) {
            $report->addWarning(
                'CLAUDE.md is a link to AGENTS.md; the import block was not added and the file is unchanged.',
            );

            return;
        }

        if (($this->isLinkLeavingProject)($projectRoot, $target)) {
            $report->addWarning('CLAUDE.md is a link that leads out of the project; the file is unchanged.');

            return;
        }

        try {
            $analysis = ($this->analyze)($target);
        } catch (InstallFailedException) {
            $report->addWarning(
                'CLAUDE.md has corrupt managed-block markers; the import block was not added, file unchanged.',
            );

            return;
        }
        if ($analysis->hasManagedBlock || ($this->hasAgentsImport)($analysis->preBlock . $analysis->postBlock)) {
            return;
        }

        $existing = $analysis->preBlock;
        $content = ($this->buildContent)($existing, ($this->detectLineEnding)($existing));
        if (@file_put_contents($target, $content) === false) {
            throw new InstallFailedException(sprintf('Could not write CLAUDE.md to "%s".', $target));
        }

        ($this->recordSelfSet)($projectRoot, self::FILE, new SelfSetEntry(!$analysis->fileExisted));
    }
}
