<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\AgentsMdAnalysis;
use JardisTools\DevSkills\Data\AggregateAgentsResult;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class AggregateAgentsMd
{
    /** @var Closure(string): AgentsMdAnalysis */
    private readonly Closure $analyze;

    /** @var Closure(string): string */
    private readonly Closure $backup;

    /** @var Closure(list<AgentsDescriptor>): string */
    private readonly Closure $buildBlock;

    public function __construct(
        private readonly Filesystem $filesystem,
        ?Closure $analyze = null,
        ?Closure $backup = null,
        ?Closure $buildBlock = null,
    ) {
        $this->analyze = $analyze ?? (new AnalyzeAgentsMd())->__invoke(...);
        $this->backup = $backup ?? (new BackupAgentsMd())->__invoke(...);
        $this->buildBlock = $buildBlock ?? (new BuildManagedBlock())->__invoke(...);
    }

    /**
     * Writes the aggregated Jardis managed block into <projectRoot>/AGENTS.md.
     *
     * - Empty descriptor list → no file is written, result has count 0.
     * - Existing file without marker → original is moved to AGENTS.md.backup
     *   and preserved above the new block.
     * - Existing file with marker → block replaced in place, user content
     *   around it untouched.
     *
     * @param list<AgentsDescriptor> $descriptors
     */
    public function __invoke(array $descriptors, string $projectRoot): AggregateAgentsResult
    {
        if ($descriptors === []) {
            return new AggregateAgentsResult(0, null);
        }

        $target = $projectRoot . '/AGENTS.md';
        $this->filesystem->ensureDirectoryExists($projectRoot);

        $analysis = ($this->analyze)($target);

        $backupPath = null;
        if ($analysis->fileExisted && !$analysis->hasManagedBlock) {
            $backupPath = ($this->backup)($target);
        }

        $block = ($this->buildBlock)($descriptors);
        $payload = $this->composePayload($analysis, $block, $backupPath !== null);

        if (file_put_contents($target, $payload) === false) {
            throw new InstallFailedException(sprintf('Could not write AGENTS.md to "%s".', $target));
        }

        return new AggregateAgentsResult(count($descriptors), $backupPath);
    }

    private function composePayload(AgentsMdAnalysis $analysis, string $block, bool $backedUp): string
    {
        if ($analysis->hasManagedBlock) {
            return $analysis->preBlock . $block . $analysis->postBlock;
        }

        if ($backedUp && $analysis->preBlock !== '') {
            return rtrim($analysis->preBlock, "\n") . "\n\n" . $block . "\n";
        }

        return $block . "\n";
    }
}
