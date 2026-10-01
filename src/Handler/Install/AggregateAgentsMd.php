<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\AgentsMdAnalysis;
use JardisTools\DevSkills\Data\AggregateAgentsResult;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Writes the aggregated managed block into AGENTS.md. Never through a link: when AGENTS.md is a link (out
 * of the project, dangling, or to a file inside it such as CLAUDE.md) or lies behind one, nothing is
 * analyzed, backed up or written; the result carries a warning and the install goes on.
 */
final class AggregateAgentsMd
{
    /** @var Closure(string): AgentsMdAnalysis */
    private readonly Closure $analyze;

    /** @var Closure(string): string */
    private readonly Closure $backup;

    /** @var Closure(list<AgentsDescriptor>, bool, string): string */
    private readonly Closure $buildBlock;

    /** @var Closure(int): ?string */
    private readonly Closure $sizeWarning;

    /**
     * @param Closure(string, string): bool $isPathBehindLink
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Closure $isPathBehindLink,
        ?Closure $analyze = null,
        ?Closure $backup = null,
        ?Closure $buildBlock = null,
        ?Closure $sizeWarning = null,
    ) {
        $this->analyze = $analyze ?? (new AnalyzeAgentsMd())->__invoke(...);
        $this->backup = $backup ?? (new BackupAgentsMd())->__invoke(...);
        $this->buildBlock = $buildBlock ?? (new BuildManagedBlock())->__invoke(...);
        $this->sizeWarning = $sizeWarning ?? (new BuildAgentsMdSizeWarning())->__invoke(...);
    }

    /**
     * Writes the aggregated Jardis managed block into <projectRoot>/AGENTS.md.
     *
     * - Empty descriptor list AND catalog not installed AND no router text → no file is written,
     *   result has count 0.
     * - Empty descriptor list AND (catalog installed OR router text given) → managed block
     *   without vendor sources is written.
     * - Router text (may be empty) opens the managed block, before the vendor aggregation.
     * - A file above the Codex size limit is still written; the result carries a warning.
     * - AGENTS.md is a link or lies behind one → no block, no backup, nothing written; result has count 0
     *   and a warning that names the way out (a regular AGENTS.md, imported by CLAUDE.md via `@AGENTS.md`).
     * - A write failure (e.g. a directory at the target) is a core error: InstallFailedException.
     * - Existing file without marker → original is moved to AGENTS.md.backup
     *   and preserved above the new block.
     * - Existing file with marker → block replaced in place, user content
     *   around it untouched.
     *
     * @param list<AgentsDescriptor> $descriptors
     */
    public function __invoke(
        array $descriptors,
        string $projectRoot,
        bool $catalogInstalled = false,
        string $routerText = '',
    ): AggregateAgentsResult {
        if ($descriptors === [] && !$catalogInstalled && $routerText === '') {
            return new AggregateAgentsResult(0, null);
        }

        $target = $projectRoot . '/AGENTS.md';
        $this->filesystem->ensureDirectoryExists($projectRoot);

        if (($this->isPathBehindLink)($projectRoot, $target)) {
            return new AggregateAgentsResult(0, null, skippedWarning: $this->linkWarning());
        }

        $analysis = ($this->analyze)($target);

        $backupPath = null;
        if ($analysis->fileExisted && !$analysis->hasManagedBlock) {
            $backupPath = ($this->backup)($target);
        }

        $block = ($this->buildBlock)($descriptors, $catalogInstalled, $routerText);
        $payload = $this->composePayload($analysis, $block, $backupPath !== null);

        if (@file_put_contents($target, $payload) === false) {
            throw new InstallFailedException(sprintf('Could not write AGENTS.md to "%s".', $target));
        }

        return new AggregateAgentsResult(
            count($descriptors),
            $backupPath,
            $analysis->healedDuplicateBlock,
            ($this->sizeWarning)(strlen($payload)),
            !$analysis->fileExisted,
        );
    }

    private function linkWarning(): string
    {
        return 'AGENTS.md is a link or lies behind one; the managed block was not written and the file is'
            . ' unchanged. To keep the block, make AGENTS.md a regular file and let CLAUDE.md import it with'
            . ' `@AGENTS.md`.';
    }

    private function composePayload(AgentsMdAnalysis $analysis, string $block, bool $backedUp): string
    {
        if ($analysis->hasManagedBlock) {
            return $analysis->preBlock . $block . $analysis->postBlock;
        }

        if ($backedUp && $analysis->preBlock !== '') {
            $eol = str_contains($analysis->preBlock, "\r\n") ? "\r\n" : "\n";

            return rtrim($analysis->preBlock, "\r\n") . $eol . $eol . $block . "\n";
        }

        return $block . "\n";
    }
}
