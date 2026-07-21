<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Unit\Data;

use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\UninstallReport;
use PHPUnit\Framework\TestCase;

final class UninstallReportTest extends TestCase
{
    public function testCollectsRemovedSkills(): void
    {
        $report = new UninstallReport();
        $report->addRemovedSkill('adapter-cache');
        $report->addRemovedSkill('plan-requirements');

        self::assertSame(2, $report->removedSkillCount());
        self::assertSame(['adapter-cache', 'plan-requirements'], $report->removedSkills());
    }

    public function testAgentsMdActionDefaultsToUntouched(): void
    {
        $report = new UninstallReport();

        self::assertSame(AgentsMdUninstallAction::Untouched, $report->agentsMdAction());
    }

    public function testAgentsMdActionCanBeSet(): void
    {
        $report = new UninstallReport();
        $report->setAgentsMdAction(AgentsMdUninstallAction::BlockStripped);

        self::assertSame(AgentsMdUninstallAction::BlockStripped, $report->agentsMdAction());
    }
}
