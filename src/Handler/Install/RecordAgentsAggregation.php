<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\AggregateAgentsResult;
use JardisTools\DevSkills\Data\InstallReport;

final class RecordAgentsAggregation
{
    public function __invoke(InstallReport $report, AggregateAgentsResult $result): void
    {
        $report->setAgentsFilesAggregated($result->aggregatedCount);
        $report->setAgentsMdBackupPath($result->backupPath);
        $report->setAgentsMdHealed($result->healedDuplicateBlock);
    }
}
