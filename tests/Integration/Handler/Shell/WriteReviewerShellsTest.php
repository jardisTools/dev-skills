<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Shell;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Shell\WriteReviewerShells;
use JardisTools\DevSkills\InstallAddons;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

final class WriteReviewerShellsTest extends TestCase
{
    private const SOURCES = WriteReviewerShells::SOURCE_DIR;

    private TempProject $project;
    private TempProject $plugin;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-project-');
        $this->plugin = new TempProject('dev-skills-plugin-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->plugin->cleanup();
    }

    public function testWritesFiveShellsPerSourceAndNotesThemInTheManifest(): void
    {
        $this->addFixtureSource('security-reviewer');
        $this->addFixtureSource('test-reviewer');

        $report = $this->write();

        self::assertSame([], $report->warnings());
        $expected = [];
        foreach (['security-reviewer', 'test-reviewer'] as $role) {
            foreach (ShellFormat::cases() as $format) {
                $path = $format->pathFor($role);
                self::assertFileExists($this->project->path($path));
                $expected[] = $path;
            }
        }
        sort($expected);
        $selfSet = $this->manifest()->selfSet;
        self::assertSame($expected, array_keys($selfSet));
        foreach ($selfSet as $entry) {
            self::assertTrue($entry->fileCreated);
        }
    }

    public function testNoShellWithoutSource(): void
    {
        $this->addFixtureSource('test-reviewer');

        $this->write();

        self::assertFileDoesNotExist($this->project->path('.claude/agents/security-reviewer.md'));
        self::assertSame(['test-reviewer.md'], array_map('basename', glob($this->project->path('.claude/agents/*')) ?: []));
        self::assertCount(5, $this->manifest()->selfSet);
    }

    public function testSourceWithoutValidFrontmatterWarnsAndYieldsNoShell(): void
    {
        $this->addFixtureSource('test-reviewer');
        $this->plugin->writeFile(self::SOURCES . '/broken.md', "Just text, no frontmatter.\n");
        $this->plugin->writeFile(self::SOURCES . '/mismatch.md', "---\nname: other\ndescription: d\n---\nBody\n");

        $report = $this->write();

        self::assertCount(2, $report->warnings());
        self::assertStringContainsString('"broken.md"', $report->warnings()[0]);
        self::assertStringContainsString('"mismatch.md"', $report->warnings()[1]);
        self::assertSame([], glob($this->project->path('.*/agents/broken*')) ?: []);
        self::assertSame([], glob($this->project->path('.*/agents/mismatch*')) ?: []);
        self::assertFileExists($this->project->path('.claude/agents/test-reviewer.md'));
    }

    public function testMissingSourceFolderCreatesNothingAndDoesNotWarn(): void
    {
        $before = TreeSnapshot::of($this->project->root);

        $report = $this->write();

        self::assertSame([], $report->warnings());
        self::assertSame($before, TreeSnapshot::of($this->project->root));
    }

    public function testForeignFileAtTargetStaysByteEqualAndWarns(): void
    {
        $this->plugin->writeFile(
            self::SOURCES . '/stage-verifier.md',
            "---\nname: stage-verifier\ndescription: Verifies a stage.\n---\nBody\n",
        );
        $foreign = "name = \"stage-verifier\"\r\ndescription = \"mine\"\r\ndeveloper_instructions = \"mine\"\r\n";
        $this->project->writeFile('.codex/agents/stage-verifier.toml', $foreign);

        $report = $this->write();

        self::assertSame($foreign, file_get_contents($this->project->path('.codex/agents/stage-verifier.toml')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('.codex/agents/stage-verifier.toml', $report->warnings()[0]);
        self::assertFileExists($this->project->path('.claude/agents/stage-verifier.md'));
        self::assertArrayNotHasKey('.codex/agents/stage-verifier.toml', $this->manifest()->selfSet);
        self::assertCount(4, $this->manifest()->selfSet);
    }

    public function testOnlyManifestFilesAreOverwritten(): void
    {
        $this->addFixtureSource('test-reviewer');
        $this->project->writeFile('.cursor/agents/test-reviewer.md', "my own agent\n");
        $this->write();
        $managedPath = $this->project->path('.claude/agents/test-reviewer.md');
        $rendered = (string) file_get_contents($managedPath);
        file_put_contents($managedPath, "edited by hand\n");

        $report = $this->write();

        self::assertSame($rendered, file_get_contents($managedPath), 'the manifest file is regenerated');
        self::assertSame("my own agent\n", file_get_contents($this->project->path('.cursor/agents/test-reviewer.md')));
        self::assertCount(1, $report->warnings(), 'only the foreign file is reported');
    }

    public function testAnUnchangedRunWritesNothingAndWarnsNot(): void
    {
        $this->addFixtureSource('test-reviewer');
        $this->write();
        $before = TreeSnapshot::of($this->project->root);

        $report = $this->write();

        self::assertSame([], $report->warnings());
        self::assertSame($before, TreeSnapshot::of($this->project->root));
    }

    public function testShellWriteObstacleWarnsAndRunContinues(): void
    {
        $this->addFixtureSource('test-reviewer');
        $this->project->mkdir('.codex/agents/test-reviewer.toml');
        $this->project->writeFile('.gemini', 'a file where the folder should be');
        $writer = AddonFactory::writeReviewerShells($this->plugin->root);
        $after = $this->project->path('after.txt');

        $report = new InstallReport();
        (new InstallAddons([
            'reviewer-shells' => $writer->__invoke(...),
            'after' => static function () use ($after): void {
                file_put_contents($after, 'done');
            },
        ]))($this->project->root, $this->project->path('vendor'), $report);

        self::assertCount(2, $report->warnings());
        self::assertStringContainsString('.codex/agents/test-reviewer.toml', implode("\n", $report->warnings()));
        self::assertStringContainsString('.gemini/agents', implode("\n", $report->warnings()));
        self::assertDirectoryExists($this->project->path('.codex/agents/test-reviewer.toml'));
        self::assertFileExists($this->project->path('.claude/agents/test-reviewer.md'));
        self::assertFileExists($this->project->path('.github/agents/test-reviewer.agent.md'));
        self::assertSame('done', file_get_contents($after));
    }

    public function testNothingIsWrittenThroughLinksLeavingTheProject(): void
    {
        $this->addFixtureSource('test-reviewer');
        $outside = new TempProject('dev-skills-outside-');
        try {
            $this->project->mkdir('.claude');
            symlink($outside->root, $this->project->path('.claude/agents'));
            $this->project->mkdir('.cursor/agents');
            $outside->writeFile('keep.md', 'keep');
            symlink($outside->path('keep.md'), $this->project->path('.cursor/agents/test-reviewer.md'));

            $report = $this->write();

            self::assertSame(['keep.md' => 'keep'], $this->listing($outside->root));
            self::assertCount(2, $report->warnings());
            self::assertTrue(is_link($this->project->path('.cursor/agents/test-reviewer.md')));
            self::assertFileExists($this->project->path('.codex/agents/test-reviewer.toml'));
        } finally {
            $outside->cleanup();
        }
    }

    public function testTheInstallerWritesShellsBeforeTheExcludeBlockAndTheBlockListsThem(): void
    {
        $this->plugin->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');
        $this->addFixtureSource('test-reviewer');
        $this->project->writeFile('.gitignore', "vendor/\n");
        GitRepo::init($this->project->root);
        GitRepo::commitAll($this->project->root);

        $report = (new SkillInstaller(
            config: PluginConfig::all()->withProcessDocs(\JardisTools\DevSkills\Data\ProcessDocsMode::Local, null),
            pluginRoot: $this->plugin->root,
        ))($this->project->root, $this->project->path('vendor'));

        self::assertSame([], $report->warnings());
        $exclude = (string) file_get_contents($this->project->path('.git/info/exclude'));
        foreach (ShellFormat::cases() as $format) {
            self::assertStringContainsString("\n/" . $format->pathFor('test-reviewer') . "\n", $exclude);
        }
        self::assertNotContains('.claude/agents/test-reviewer.md', GitRepo::visiblePaths($this->project->root));
    }

    private function addFixtureSource(string $role): void
    {
        $this->plugin->writeFile(
            self::SOURCES . '/' . $role . '.md',
            (string) file_get_contents(__DIR__ . '/../../../Fixture/Reviewers/' . $role . '.md'),
        );
    }

    private function write(): InstallReport
    {
        $report = new InstallReport();
        AddonFactory::writeReviewerShells($this->plugin->root)($this->project->root, $this->project->path('vendor'), $report);

        return $report;
    }

    private function manifest(): Manifest
    {
        $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest;
        self::assertNotNull($manifest);

        return $manifest;
    }

    /**
     * @return array<string, string>
     */
    private function listing(string $directory): array
    {
        $files = [];
        foreach (glob($directory . '/*') ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }

        return $files;
    }
}
