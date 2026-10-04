<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use JardisTools\DevSkills\Handler\Install\ReplaceExcludeBlock;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\SkillUninstaller;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * The `process-docs` switch through the real installer and uninstaller: configuration, the note
 * that the plugin created AGENTS.md, the exclude block, and its removal.
 */
final class ProcessDocsEndToEndTest extends TestCase
{
    private TempProject $project;
    private TempProject $pluginRepo;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-project-');
        $this->pluginRepo = new TempProject('dev-skills-plugin-');
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');
        $this->project->writeFile('vendor/jardisadapter/cache/AGENTS.md', "# cache\nCache rules.");
        $this->project->writeFile('.gitignore', "vendor/\n");
        GitRepo::init($this->project->root);
        GitRepo::commitAll($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->pluginRepo->cleanup();
    }

    public function testDefaultWritesOnlyTheBackupLineAndEverythingElseIsVisible(): void
    {
        $report = $this->install([]);

        self::assertSame([], $report->warnings());
        self::assertSame(
            ReplaceExcludeBlock::BEGIN . "\n.claude/.jardis-backup/\ntmp/archiv/\n" . ReplaceExcludeBlock::END . "\n",
            $this->excludeBlock(),
        );
        self::assertContains('AGENTS.md', GitRepo::visiblePaths($this->project->root));
    }

    public function testLocalHidesWhatThePluginCreatedAndTheUninstallRestoresTheExcludeFile(): void
    {
        $exclude = $this->project->path('.git/info/exclude');
        $before = (string) file_get_contents($exclude);
        $this->project->writeFile('docs/vorhaben/plan.md', 'x');

        $report = $this->install(['process-docs' => 'local']);

        self::assertSame([], $report->warnings());
        $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest;
        self::assertTrue($manifest?->selfSet['AGENTS.md']->fileCreated ?? false, 'AGENTS.md was created by the plugin');
        self::assertTrue($manifest?->selfSet['CLAUDE.md']->fileCreated ?? false);
        self::assertSame([], GitRepo::visiblePaths($this->project->root));

        $uninstall = (new SkillUninstaller())($this->project->root);

        self::assertSame([], $uninstall->warnings());
        self::assertSame($before, file_get_contents($exclude));
    }

    public function testAnExistingAgentsMdIsNeverNotedAsCreatedNorExcluded(): void
    {
        $this->project->writeFile('AGENTS.md', "# mine\n");

        $this->install(['process-docs' => 'local']);

        $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest;
        self::assertArrayNotHasKey('AGENTS.md', $manifest->selfSet ?? []);
        self::assertStringNotContainsString('AGENTS.md', $this->excludeBlock());
        self::assertContains('AGENTS.md', GitRepo::visiblePaths($this->project->root));
    }

    public function testTheCreatedNoteSurvivesALaterRun(): void
    {
        $this->install(['process-docs' => 'committed']);
        $report = $this->install(['process-docs' => 'local']);

        self::assertSame([], $report->warnings());
        self::assertStringContainsString("\n/AGENTS.md\n", $this->excludeBlock());
    }

    public function testWithoutGitRepositoryTheRunWarnsAndDoesNotFail(): void
    {
        $plain = new TempProject('dev-skills-nogit-');
        try {
            $plain->writeFile('vendor/jardisadapter/cache/AGENTS.md', "# cache\nCache rules.");
            $report = $this->installer(['process-docs' => 'local'])($plain->root, $plain->path('vendor'));

            self::assertSame(1, count(array_filter(
                $report->warnings(),
                static fn (string $warning): bool => str_contains($warning, 'no Git repository'),
            )));
            self::assertFileExists($plain->path('AGENTS.md'), 'the core still ran');
        } finally {
            $plain->cleanup();
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function installer(array $settings): SkillInstaller
    {
        return new SkillInstaller(
            config: (new ReadPluginConfig())(['jardis/dev-skills' => $settings]),
            pluginRoot: $this->pluginRepo->root,
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function install(array $settings): \JardisTools\DevSkills\Data\InstallReport
    {
        return $this->installer($settings)($this->project->root, $this->project->path('vendor'));
    }

    private function excludeBlock(): string
    {
        $content = (string) file_get_contents($this->project->path('.git/info/exclude'));
        $start = (int) strpos($content, ReplaceExcludeBlock::BEGIN);

        return substr($content, $start, (int) strpos($content, ReplaceExcludeBlock::END) + strlen(ReplaceExcludeBlock::END) + 1 - $start);
    }
}
