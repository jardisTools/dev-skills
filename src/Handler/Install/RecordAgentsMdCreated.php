<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\SelfSetEntry;

/**
 * Notes in the manifest that the plugin created AGENTS.md itself (the aggregation found no file).
 * Only `process-docs=local` needs it, to keep a file out of the commit that is the plugin's alone;
 * it is noted in both modes, so a later switch to `local` still knows. A file that was there
 * before is never noted, and a later run does not overwrite the note.
 */
final class RecordAgentsMdCreated
{
    public const FILE = 'AGENTS.md';

    /**
     * @param Closure(string, string, SelfSetEntry): void $recordSelfSet
     */
    public function __construct(private readonly Closure $recordSelfSet)
    {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        if ($report->agentsMdCreated()) {
            ($this->recordSelfSet)($projectRoot, self::FILE, new SelfSetEntry(true));
        }
    }
}
