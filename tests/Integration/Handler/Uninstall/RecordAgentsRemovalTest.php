<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Uninstall\RecordAgentsRemoval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordAgentsRemovalTest extends TestCase
{
    public function testSkippedLinkIsRecordedWithWarning(): void
    {
        $report = new UninstallReport();

        (new RecordAgentsRemoval())($report, AgentsMdUninstallAction::SkippedLink);

        self::assertSame(AgentsMdUninstallAction::SkippedLink, $report->agentsMdAction());
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('AGENTS.md is a link', $report->warnings()[0]);
        self::assertStringContainsString('older release', $report->warnings()[0]);
    }

    #[DataProvider('nonLinkActions')]
    public function testOtherActionsAreRecordedWithoutWarning(AgentsMdUninstallAction $action): void
    {
        $report = new UninstallReport();

        (new RecordAgentsRemoval())($report, $action);

        self::assertSame($action, $report->agentsMdAction());
        self::assertSame([], $report->warnings());
    }

    /**
     * @return array<string, array{AgentsMdUninstallAction}>
     */
    public static function nonLinkActions(): array
    {
        return [
            'file deleted' => [AgentsMdUninstallAction::FileDeleted],
            'block stripped' => [AgentsMdUninstallAction::BlockStripped],
            'untouched' => [AgentsMdUninstallAction::Untouched],
            'corrupt' => [AgentsMdUninstallAction::Corrupt],
        ];
    }
}
