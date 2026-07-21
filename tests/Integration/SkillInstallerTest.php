<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
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

    public function testDefaultOnConfigInstallsCatalogAndLifecycleSkills(): void
    {
        // When ReadPluginConfig sees no bundled-skills key it returns defaultOn()
        // (include: ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer']).
        // Only these three are copied; other bundle skills remain absent.
        $this->pluginRepo->writeFile('skills/jardis-catalog/SKILL.md', 'catalog-skill');
        $this->pluginRepo->writeFile('skills/jardis-start-here/SKILL.md', 'start-here-skill');
        $this->pluginRepo->writeFile('skills/jardis-mcp-consumer/SKILL.md', 'mcp-consumer-skill');
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');
        $this->pluginRepo->writeFile('skills/rules-architecture/SKILL.md', 'rules-skill');

        $installer = new SkillInstaller(
            config: PluginConfig::defaultOn(),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(3, $report->installedSkillCount());
        self::assertSame(
            ['jardis-catalog', 'jardis-mcp-consumer', 'jardis-start-here'],
            $report->installedSkills(),
        );
        self::assertFileExists($this->project->path('.claude/skills/jardis-catalog/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/jardis-start-here/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/jardis-mcp-consumer/SKILL.md'));
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

    public function testAggregatesToSingleManagedBlockWhenSourceHasOwnBlock(): void
    {
        // foundation aggregates kernel, whose committed AGENTS.md already carries
        // its own managed block — its markers must not nest into the result.
        $this->project->writeFile(
            'vendor/jardiscore/foundation/AGENTS.md',
            "# foundation\nFoundation rules.\n",
        );
        $this->project->writeFile(
            'vendor/jardiscore/kernel/AGENTS.md',
            "# kernel\nKernel rules.\n\n"
            . AnalyzeAgentsMd::HEADER . "\n"
            . "kernel aggregated body\n"
            . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );
        $installer($this->project->root, $this->project->path('vendor'));

        $agents = file_get_contents($this->project->path('AGENTS.md'));

        self::assertSame(1, substr_count($agents, AnalyzeAgentsMd::HEADER));
        self::assertSame(1, substr_count($agents, AnalyzeAgentsMd::FOOTER));
        self::assertStringContainsString('Foundation rules.', $agents);
        self::assertStringContainsString('Kernel rules.', $agents);
        self::assertStringNotContainsString('kernel aggregated body', $agents);
    }

    public function testRepeatedInstallsAreIdempotentAndDoNotCascadeBackups(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            'cache-skill',
        );
        $this->project->writeFile(
            'vendor/jardiscore/kernel/AGENTS.md',
            "# kernel\nKernel rules.\n\n"
            . AnalyzeAgentsMd::HEADER . "\n"
            . "kernel aggregated body\n"
            . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );

        $installer($this->project->root, $this->project->path('vendor'));
        $afterFirst = file_get_contents($this->project->path('AGENTS.md'));

        $installer($this->project->root, $this->project->path('vendor'));
        $afterSecond = file_get_contents($this->project->path('AGENTS.md'));
        $backupsAfterSecond = $this->backupDirs();

        $installer($this->project->root, $this->project->path('vendor'));
        $afterThird = file_get_contents($this->project->path('AGENTS.md'));
        $backupsAfterThird = $this->backupDirs();

        self::assertSame($afterFirst, $afterSecond);
        self::assertSame($afterSecond, $afterThird);
        self::assertSame(1, substr_count($afterThird, AnalyzeAgentsMd::HEADER));
        // No cascade: backup set stable across runs and never a second stage.
        self::assertSame($backupsAfterSecond, $backupsAfterThird);
        foreach ($backupsAfterThird as $backup) {
            self::assertStringEndsNotWith('.backup.backup', $backup);
        }
    }

    /**
     * @return list<string>
     */
    private function backupDirs(): array
    {
        $matches = glob($this->project->path('.claude/skills') . '/*.backup*', GLOB_ONLYDIR);
        $matches = $matches === false ? [] : array_map('basename', $matches);
        sort($matches);

        return $matches;
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
