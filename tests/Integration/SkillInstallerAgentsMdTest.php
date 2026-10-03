<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\SkillUninstaller;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * The `agents-md` key: a project that is itself a jardis* package gets no managed block in AGENTS.md,
 * no CLAUDE.md import and no Gemini entry; everything else of the install runs as before.
 */
final class SkillInstallerAgentsMdTest extends TestCase
{
    private const OLD_BLOCK_HEADER = '<!-- BEGIN jardis/dev-skills — managed block, do not edit by hand -->';
    private const OLD_BLOCK_FOOTER = '<!-- END jardis/dev-skills -->';

    private TempProject $project;
    private TempProject $pluginRepo;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-agentsmd-project-');
        $this->pluginRepo = new TempProject('dev-skills-agentsmd-plugin-');
        GitRepo::init($this->project->root);
        $this->pluginRepo->writeFile('skills/foundation-alpha/SKILL.md', 'alpha');
        $this->pluginRepo->writeFile('router/AGENTS-router.md', "Router text.\n");
        $this->project->writeFile('vendor/jardisadapter/cache/AGENTS.md', "# cache\nCache rules.\n");
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->pluginRepo->cleanup();
    }

    public function testJardisPackageWithoutKeyWritesNoAgentsMdNoImportNoGeminiButEverythingElse(): void
    {
        $this->project->writeFile('CLAUDE.md', "# Mine\n");

        $report = $this->install([], 'jardiscore/kernel');

        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
        self::assertSame("# Mine\n", file_get_contents($this->project->path('CLAUDE.md')));
        self::assertFileDoesNotExist($this->project->path('.gemini/settings.json'));
        self::assertSame(0, $report->agentsFilesAggregated());
        self::assertFalse($report->agentsMdCreated());
        self::assertSame([], $report->warnings());
        self::assertFileExists($this->project->path('.claude/skills/foundation-alpha/SKILL.md'));
        self::assertFileExists($this->project->path('.agents/skills/foundation-alpha/SKILL.md'));
        self::assertFileExists($this->project->path(Manifest::FILE));
        self::assertSame([], $this->selfSet());
        self::assertStringContainsString('BEGIN jardis/dev-skills', $this->excludeFile());
    }

    public function testReviewerShellsStillComeInTheNoneMode(): void
    {
        $withBlock = new TempProject('dev-skills-agentsmd-ref-');
        GitRepo::init($withBlock->root);
        try {
            $this->install([], 'jardiscore/kernel');
            $this->installInto($withBlock, [], 'acme/app');

            self::assertSame($this->shellFiles($withBlock), $this->shellFiles($this->project));
        } finally {
            $withBlock->cleanup();
        }
    }

    public function testExistingBlockIsRemovedAndTheHeadStaysByteForByte(): void
    {
        $head = "# kernel\n\nOwn rules, no trailing newline mix.\n\n";
        $this->project->writeFile('AGENTS.md', $head . $this->block());

        $report = $this->install([], 'jardiscore/kernel');

        self::assertSame($head . "\n", file_get_contents($this->project->path('AGENTS.md')));
        self::assertSame([], $report->warnings());
    }

    public function testBlockOnlyFileCreatedByThePluginIsDeletedTogetherWithImportAndGeminiEntry(): void
    {
        $this->install(['agents-md' => 'aggregate'], 'acme/app');
        self::assertFileExists($this->project->path('AGENTS.md'));
        self::assertFileExists($this->project->path('CLAUDE.md'));
        self::assertFileExists($this->project->path('.gemini/settings.json'));
        self::assertArrayHasKey('AGENTS.md', $this->selfSet());

        $report = $this->install(['agents-md' => 'none'], 'acme/app');

        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
        self::assertFileDoesNotExist($this->project->path('CLAUDE.md'));
        self::assertFileDoesNotExist($this->project->path('.gemini/settings.json'));
        self::assertSame([], $this->selfSet());
        self::assertSame([], $report->warnings());
    }

    public function testImportAndGeminiEntryInForeignFilesAreTakenOutAndTheRestStays(): void
    {
        $this->project->writeFile('CLAUDE.md', "# Mine\n");
        $this->project->writeFile('.gemini/settings.json', "{\n  \"theme\": \"dark\"\n}\n");
        $this->install([], 'acme/app');
        self::assertNotSame("# Mine\n", file_get_contents($this->project->path('CLAUDE.md')));

        $this->install(['agents-md' => 'none'], 'acme/app');

        self::assertSame("# Mine\n", file_get_contents($this->project->path('CLAUDE.md')));
        self::assertSame("{\n  \"theme\": \"dark\"\n}\n", file_get_contents($this->project->path('.gemini/settings.json')));
        self::assertSame([], $this->selfSet());
    }

    public function testAggregateOnAJardisPackageKeepsTheBlockLikeToday(): void
    {
        $this->install(['agents-md' => 'aggregate'], 'jardiscore/kernel');

        $agents = (string) file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString(self::OLD_BLOCK_HEADER, $agents);
        self::assertStringContainsString('Cache rules.', $agents);
        self::assertStringContainsString('@AGENTS.md', (string) file_get_contents($this->project->path('CLAUDE.md')));
        self::assertFileExists($this->project->path('.gemini/settings.json'));
    }

    public function testNoneOnAProjectBehavesLikeNone(): void
    {
        $this->install(['agents-md' => 'none'], 'acme/app');

        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
        self::assertFileDoesNotExist($this->project->path('CLAUDE.md'));
        self::assertFileDoesNotExist($this->project->path('.gemini/settings.json'));
        self::assertFileExists($this->project->path('.claude/skills/foundation-alpha/SKILL.md'));
    }

    public function testSecondRunIsIdempotent(): void
    {
        $head = "# kernel\n\n";
        $this->project->writeFile('AGENTS.md', $head . $this->block());
        $this->install([], 'jardiscore/kernel');
        $agents = file_get_contents($this->project->path('AGENTS.md'));
        $manifest = file_get_contents($this->project->path(Manifest::FILE));

        $report = $this->install([], 'jardiscore/kernel');

        self::assertSame($agents, file_get_contents($this->project->path('AGENTS.md')));
        self::assertSame($manifest, file_get_contents($this->project->path(Manifest::FILE)));
        self::assertSame([], $report->warnings());
    }

    public function testUserFileWithoutBlockIsNeverTouched(): void
    {
        $this->project->writeFile('AGENTS.md', "# only mine\n");

        $this->install([], 'jardiscore/kernel');

        self::assertSame("# only mine\n", file_get_contents($this->project->path('AGENTS.md')));
    }

    public function testCorruptMarkersWarnAndLeaveTheFileUntouched(): void
    {
        $content = "# mine\n" . self::OLD_BLOCK_HEADER . "\nno footer\n";
        $this->project->writeFile('AGENTS.md', $content);

        $report = $this->install([], 'jardiscore/kernel');

        self::assertSame($content, file_get_contents($this->project->path('AGENTS.md')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('corrupt managed-block markers', $report->warnings()[0]);
    }

    public function testInvalidValueWarningReachesTheReport(): void
    {
        $report = $this->install(['agents-md' => 'merge'], 'jardiscore/kernel');

        self::assertContains(
            'agents-md must be "aggregate" or "none"; got "merge". Treated as agents-md=none.',
            $report->warnings(),
        );
    }

    public function testUninstallAfterNoneRunsWithoutErrorsAndKeepsUserContent(): void
    {
        $this->project->writeFile('AGENTS.md', "# kernel\n\n" . $this->block());
        $this->install([], 'jardiscore/kernel');

        $report = (new SkillUninstaller())($this->project->root);

        self::assertSame([], $report->warnings());
        self::assertSame("# kernel\n\n\n", file_get_contents($this->project->path('AGENTS.md')));
        self::assertFileDoesNotExist($this->project->path('.claude/skills/foundation-alpha/SKILL.md'));
    }

    public function testUninstallAfterNoneWithoutAnyAgentsMdIsFine(): void
    {
        $this->install([], 'jardiscore/kernel');

        $report = (new SkillUninstaller())($this->project->root);

        self::assertSame([], $report->warnings());
        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function install(array $settings, string $rootPackageName): InstallReport
    {
        return $this->installInto($this->project, $settings, $rootPackageName);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function installInto(TempProject $project, array $settings, string $rootPackageName): InstallReport
    {
        $extra = $settings === [] ? [] : ['jardis/dev-skills' => $settings];
        $config = (new ReadPluginConfig())($extra, $rootPackageName);
        $installer = new SkillInstaller(config: $config, pluginRoot: $this->pluginRepo->root);

        return $installer($project->root, $project->path('vendor'), '1.8.0');
    }

    /**
     * @return array<string, \JardisTools\DevSkills\Data\SelfSetEntry>
     */
    private function selfSet(): array
    {
        return (new ReadManifest())($this->project->path(Manifest::FILE), '1.8.0')->manifest?->selfSet ?? [];
    }

    private function excludeFile(): string
    {
        return (string) file_get_contents($this->project->path('.git/info/exclude'));
    }

    private function block(): string
    {
        return self::OLD_BLOCK_HEADER . "\nRouter text.\n\n# cache\nCache rules.\n" . self::OLD_BLOCK_FOOTER . "\n";
    }

    /**
     * @return list<string>
     */
    private function shellFiles(TempProject $project): array
    {
        $found = [];
        foreach (['.claude/agents', '.cursor', '.github', '.codex', '.gemini/agents'] as $dir) {
            if (!is_dir($project->path($dir))) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($project->path($dir), \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                $found[] = substr($file->getPathname(), strlen($project->root));
            }
        }
        sort($found);

        return $found;
    }
}
