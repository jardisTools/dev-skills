<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\SkillUninstaller;
use JardisTools\DevSkills\UninstallAddons;
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

    public function testUninstallRemovesBlockAndGeminiEntryBeforeManifestIsDeleted(): void
    {
        $pluginRepo = new TempProject('dev-skills-plugin-');
        try {
            $pluginRepo->writeFile('skills/foundation-alpha/SKILL.md', 'a');
            $claudeMd = "# Mine\n\nMy rules.\n";
            $gemini = "{\n  \"theme\": \"dark\"\n}\n";
            $this->project->writeFile('CLAUDE.md', $claudeMd);
            $this->project->writeFile('.gemini/settings.json', $gemini);
            (new SkillInstaller(config: PluginConfig::all(), pluginRoot: $pluginRepo->root))(
                $this->project->root,
                $this->project->path('vendor'),
                '1.0.0',
            );
            self::assertNotSame($claudeMd, file_get_contents($this->project->path('CLAUDE.md')));
            self::assertNotSame($gemini, file_get_contents($this->project->path('.gemini/settings.json')));

            $report = (new SkillUninstaller())($this->project->root, '1.0.0');

            self::assertSame([], $report->warnings());
            self::assertSame($claudeMd, file_get_contents($this->project->path('CLAUDE.md')));
            self::assertSame($gemini, file_get_contents($this->project->path('.gemini/settings.json')));
            self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
            self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/foundation-alpha'));
        } finally {
            $pluginRepo->cleanup();
        }
    }

    public function testUninstallDeletesWhatTheInstallCreated(): void
    {
        $pluginRepo = new TempProject('dev-skills-plugin-');
        try {
            $pluginRepo->writeFile('skills/foundation-alpha/SKILL.md', 'a');
            (new SkillInstaller(config: PluginConfig::all(), pluginRoot: $pluginRepo->root))(
                $this->project->root,
                $this->project->path('vendor'),
                '1.0.0',
            );
            self::assertFileExists($this->project->path('CLAUDE.md'));
            self::assertFileExists($this->project->path('.gemini/settings.json'));

            (new SkillUninstaller())($this->project->root, '1.0.0');

            self::assertFileDoesNotExist($this->project->path('CLAUDE.md'));
            self::assertFileDoesNotExist($this->project->path('.gemini/settings.json'));
            self::assertDirectoryDoesNotExist($this->project->path('.gemini'));
        } finally {
            $pluginRepo->cleanup();
        }
    }

    public function testFailingUninstallAddonOnlyWarnsAndTheRestOfTheUninstallRuns(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'x');
        $addons = new UninstallAddons([
            'demo' => static function (): void {
                throw new \RuntimeException('cannot remove');
            },
        ]);

        $report = (new SkillUninstaller(null, $addons))($this->project->root);

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('add-on "demo" failed and was skipped: cannot remove', $report->warnings()[0]);
        self::assertSame(1, $report->removedSkillCount());
    }

    public function testUninstallRemovesOnlyManifestShells(): void
    {
        $pluginRepo = new TempProject('dev-skills-plugin-');
        try {
            $pluginRepo->writeFile('skills/foundation-alpha/SKILL.md', 'a');
            $pluginRepo->writeFile(
                'skills/process-review-board/reviewers/test-reviewer.md',
                (string) file_get_contents(__DIR__ . '/../Fixture/Reviewers/test-reviewer.md'),
            );
            $foreignCodex = "name = \"stage-verifier\"\n";
            $this->project->writeFile('.codex/agents/stage-verifier.toml', $foreignCodex);
            $this->project->writeFile('.claude/agents/mine.md', "my agent\n");
            (new SkillInstaller(config: PluginConfig::all(), pluginRoot: $pluginRepo->root))(
                $this->project->root,
                $this->project->path('vendor'),
                '1.0.0',
            );
            $shells = [
                '.claude/agents/test-reviewer.md', '.codex/agents/test-reviewer.toml',
                '.cursor/agents/test-reviewer.md', '.github/agents/test-reviewer.agent.md',
                '.gemini/agents/test-reviewer.md',
            ];
            $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '1.0.0')->manifest;
            $noted = array_values(array_intersect(array_keys($manifest->selfSet ?? []), $shells));
            sort($shells);
            self::assertSame($shells, $noted);
            foreach ($shells as $shell) {
                self::assertFileExists($this->project->path($shell));
            }

            $report = (new SkillUninstaller())($this->project->root, '1.0.0');

            self::assertSame([], $report->warnings());
            foreach ($shells as $shell) {
                self::assertFileDoesNotExist($this->project->path($shell), $shell);
            }
            self::assertSame($foreignCodex, file_get_contents($this->project->path('.codex/agents/stage-verifier.toml')));
            self::assertSame("my agent\n", file_get_contents($this->project->path('.claude/agents/mine.md')));
            self::assertDirectoryDoesNotExist($this->project->path('.cursor'));
            self::assertDirectoryDoesNotExist($this->project->path('.gemini'));
            self::assertDirectoryDoesNotExist($this->project->path('.github'));
        } finally {
            $pluginRepo->cleanup();
        }
    }
}
