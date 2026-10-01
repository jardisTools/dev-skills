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
 * - corrupt markers, or a link (to AGENTS.md itself, or AGENTS.md being the link to CLAUDE.md, in both cases
 *   the same file, where the block would land in AGENTS.md or import itself; out of the project, or to another
 *   file inside it): a warning, the file stays untouched. Never through a link.
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
     * @param Closure(string, string): bool   $isPathBehindLink
     */
    public function __construct(
        private readonly Closure $analyze,
        private readonly Closure $hasAgentsImport,
        private readonly Closure $detectLineEnding,
        private readonly Closure $buildContent,
        private readonly Closure $recordSelfSet,
        private readonly Closure $isPathBehindLink,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $target = $projectRoot . '/' . self::FILE;

        if (file_exists($target) && !is_file($target)) {
            throw new InstallFailedException(sprintf('"%s" exists but is not a regular file.', $target));
        }
        $realClaudeMd = realpath($target);
        if ($realClaudeMd !== false && $realClaudeMd === realpath($projectRoot . '/AGENTS.md')) {
            $report->addWarning(is_link($target)
                ? 'CLAUDE.md is a link to AGENTS.md; the import block was not added and the file is unchanged.'
                : 'AGENTS.md is a link to CLAUDE.md; the import block was not added and the file is unchanged.');

            return;
        }

        if (($this->isPathBehindLink)($projectRoot, $target)) {
            $report->addWarning($this->leadsOutOfProject($target, $projectRoot)
                ? 'CLAUDE.md is a link that leads out of the project; the file is unchanged.'
                : 'CLAUDE.md is a link; the file is unchanged.');

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

    private function leadsOutOfProject(string $target, string $projectRoot): bool
    {
        $real = realpath($target);
        $root = realpath($projectRoot);

        return $real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }
}
