<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class SkillInstallerTest extends TestCase
{
    private TempProject $project;
    private TempProject $pluginRepo;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-project-');
        $this->pluginRepo = new TempProject('dev-skills-plugin-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->pluginRepo->cleanup();
    }

    public function testInstallsVendorAndPluginSkillsAndAggregatesAgentsMd(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            'cache-skill',
        );
        $this->project->writeFile(
            'vendor/jardisadapter/cache/AGENTS.md',
            "# cache\nCache rules.",
        );
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(2, $report->installedSkillCount());
        self::assertSame(1, $report->agentsFilesAggregated());
        self::assertFileExists($this->project->path('.claude/skills/adapter-cache/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/plan-requirements/SKILL.md'));
        self::assertStringContainsString(
            'Cache rules.',
            file_get_contents($this->project->path('AGENTS.md')),
        );
    }

    public function testDefaultConfigSkipsBundledSkills(): void
    {
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');
        $this->pluginRepo->writeFile('skills/rules-architecture/SKILL.md', 'rules-skill');

        $installer = new SkillInstaller(pluginRoot: $this->pluginRepo->root);
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(0, $report->installedSkillCount());
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/plan-requirements'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-architecture'));
    }

    public function testFilteredConfigInstallsSubsetAndRemovesStale(): void
    {
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');
        $this->pluginRepo->writeFile('skills/rules-architecture/SKILL.md', 'rules-skill');
        // Stale bundled skill on disk from a previous wider config.
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', 'old');

        $installer = new SkillInstaller(
            config: PluginConfig::filtered(['plan-*'], []),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(1, $report->installedSkillCount());
        self::assertSame(['plan-requirements'], $report->installedSkills());
        self::assertSame(['rules-architecture'], $report->removedBundledSkills());
        self::assertFileExists($this->project->path('.claude/skills/plan-requirements/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-architecture'));
    }

    public function testBacksUpExistingSkillOnConflict(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            'new',
        );
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'old');

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertCount(1, $report->backedUpSkills());
        self::assertSame(
            'old',
            file_get_contents($this->project->path('.claude/skills/adapter-cache.backup/SKILL.md')),
        );
        self::assertSame(
            'new',
            file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')),
        );
    }

    public function testRunsOnEmptyVendorWithoutErrors(): void
    {
        $this->project->mkdir('vendor');

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(0, $report->installedSkillCount());
        self::assertSame(0, $report->agentsFilesAggregated());
    }
}
