<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\UninstallReport;

/**
 * Notes the outcome of the AGENTS.md removal in the report; a skipped link also adds the warning that an
 * old managed block may still stand behind it.
 */
final class RecordAgentsRemoval
{
    public function __invoke(UninstallReport $report, AgentsMdUninstallAction $action): void
    {
        $report->setAgentsMdAction($action);

        if ($action === AgentsMdUninstallAction::SkippedLink) {
            $report->addWarningIfAny(
                'AGENTS.md is a link or lies behind one; it was not followed or changed.'
                . ' A managed block from an older release may still stand behind the link.',
            );
        }
    }
}
