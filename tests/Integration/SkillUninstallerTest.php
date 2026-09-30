<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\SkillUninstaller;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

final class SkillUninstallerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testRemovesManagedSkillsAndAgentsMd(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'x');
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', 'y');
        $this->project->writeFile('.claude/skills/my-local/SKILL.md', 'local');
        $this->project->writeFile(
            'AGENTS.md',
            AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $report = (new SkillUninstaller())($this->project->root);

        self::assertSame(2, $report->removedSkillCount());
        self::assertSame(AgentsMdUninstallAction::FileDeleted, $report->agentsMdAction());
        self::assertFileDoesNotExist($this->project->path('.claude/skills/adapter-cache/SKILL.md'));
        self::assertFileDoesNotExist($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/my-local/SKILL.md'));
        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testKeepsHandWrittenAgentsMd(): void
    {
        $this->project->writeFile('AGENTS.md', "# hand written\n");

        $report = (new SkillUninstaller())($this->project->root);

        self::assertSame(AgentsMdUninstallAction::Untouched, $report->agentsMdAction());
        self::assertFileExists($this->project->path('AGENTS.md'));
    }

    public function testWithHealthyManifestRemovesOnlyItsPaths(): void
    {
        $this->project->writeFile('.claude/skills/process-alpha/SKILL.md', 'managed');
        $this->project->writeFile('.agents/skills/process-alpha/SKILL.md', 'managed');
        $this->project->writeFile('.claude/skills/adapter-mine/SKILL.md', 'mine');
        $this->project->writeFile(Manifest::FILE, json_encode([
            'schemaVersion' => 1,
            'pluginVersion' => '1.0.0',
            'paths' => [
                '.claude/skills/process-alpha' => ['source' => 'bundle', 'sha256' => str_repeat('a', 64)],
                '.agents/skills/process-alpha' => ['source' => 'bundle', 'sha256' => str_repeat('a', 64)],
            ],
        ], JSON_THROW_ON_ERROR));

        $report = (new SkillUninstaller())($this->project->root, '1.0.0');

        self::assertSame(['process-alpha'], $report->removedSkills());
        self::assertSame([], $report->warnings());
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/process-alpha'));
        self::assertFileExists($this->project->path('.claude/skills/adapter-mine/SKILL.md'));
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
    }

    public function testTooNewManifestChangesNothingNotEvenAgentsMd(): void
    {
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', 'x');
        $this->project->writeFile(Manifest::FILE, '{"schemaVersion":2,"pluginVersion":"1.0.0","paths":{}}');
        $this->project->writeFile('AGENTS.md', AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n");
        $before = TreeSnapshot::ofProject($this->project);

        $report = (new SkillUninstaller())($this->project->root, '1.0.0');

        self::assertSame($before, TreeSnapshot::ofProject($this->project));
        self::assertSame(0, $report->removedSkillCount());
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('schema 2', $report->warnings()[0]);
        self::assertStringContainsString('plugin 1.0.0', $report->warnings()[0]);
    }

    public function testDefectiveManifestRemovesNoSkillAndWarns(): void
    {
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', 'x');
        $this->project->writeFile(Manifest::FILE, 'not json');

        $report = (new SkillUninstaller())($this->project->root, '1.0.0');

        self::assertSame(0, $report->removedSkillCount());
        self::assertFileExists($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path(Manifest::FILE));
        self::assertStringContainsString('defective manifest', $report->warnings()[0]);
    }
}
