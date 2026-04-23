<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Unit\Data;

use JardisTools\DevSkills\Data\InstallReport;
use PHPUnit\Framework\TestCase;

final class InstallReportTest extends TestCase
{
    public function testCollectsInstalledSkills(): void
    {
        $report = new InstallReport();
        $report->addInstalledSkill('adapter-cache');
        $report->addInstalledSkill('support-data');

        self::assertSame(2, $report->installedSkillCount());
        self::assertSame(['adapter-cache', 'support-data'], $report->installedSkills());
    }

    public function testTracksBackups(): void
    {
        $report = new InstallReport();
        $report->addBackedUpSkill('adapter-cache', '/tmp/x.backup');

        self::assertSame(
            [['skill' => 'adapter-cache', 'backupPath' => '/tmp/x.backup']],
            $report->backedUpSkills(),
        );
    }

    public function testAgentsFilesAggregatedStartsAtZero(): void
    {
        $report = new InstallReport();

        self::assertSame(0, $report->agentsFilesAggregated());
        $report->setAgentsFilesAggregated(3);
        self::assertSame(3, $report->agentsFilesAggregated());
    }
}
