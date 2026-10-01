<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ProcessDocsMode;
use JardisTools\DevSkills\Data\SelfSetEntry;
use JardisTools\DevSkills\Handler\Install\ReplaceExcludeBlock;
use JardisTools\DevSkills\Handler\Manifest\WriteManifest;
use JardisTools\DevSkills\InstallAddons;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

final class SyncExcludeBlockTest extends TestCase
{
    private const BEGIN = ReplaceExcludeBlock::BEGIN;
    private const END = ReplaceExcludeBlock::END;
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        GitRepo::init($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testCommittedBlockHoldsOnlyBackupFolder(): void
    {
        $this->writeManifest(selfSet: ['CLAUDE.md' => new SelfSetEntry(true)]);
        $this->project->writeFile('CLAUDE.md', 'c');
        $this->project->writeFile('.claude/.jardis-backup/b/SKILL.md', 'x');

        $report = $this->sync(ProcessDocsMode::Committed);

        self::assertSame([], $report->warnings());
        self::assertSame(
            self::BEGIN . "\n.claude/.jardis-backup/\n" . self::END . "\n",
            $this->blockOf($this->exclude()),
        );
        self::assertSame(
            [
                '.agents/skills/foo/SKILL.md',
                '.claude/skills/.jardis-managed.json',
                '.claude/skills/bar/SKILL.md',
                '.claude/skills/foo/SKILL.md',
                'CLAUDE.md',
            ],
            $this->visiblePaths(),
            'everything the plugin created is meant for the commit; only the backup folder is hidden',
        );
    }

    public function testLocalBlockAddsModePathsAndManifestPaths(): void
    {
        $this->writeManifest(selfSet: [
            'CLAUDE.md' => new SelfSetEntry(true),
            '.gemini/settings.json' => new SelfSetEntry(true),
            'GEMINI.md' => new SelfSetEntry(false),
        ]);
        $this->project->writeFile('CLAUDE.md', 'c');
        $this->project->writeFile('.gemini/settings.json', '{}');

        $report = $this->sync(ProcessDocsMode::Local);

        self::assertSame([], $report->warnings());
        self::assertSame(
            self::BEGIN . "\n"
            . ".claude/.jardis-backup/\ndocs/vorhaben/\ndocs/digests/\n.claude/wissen/\n"
            . ".agents/skills/foo\n.claude/skills/bar\n.claude/skills/foo\n.claude/skills/.jardis-managed.json\n"
            . "/.gemini/settings.json\n/CLAUDE.md\n"
            . self::END . "\n",
            $this->blockOf($this->exclude()),
        );

        $this->project->writeFile('docs/vorhaben/plan.md', 'x');
        $this->project->writeFile('docs/digests/d.md', 'x');
        $this->project->writeFile('.claude/wissen/w.md', 'x');
        $this->project->writeFile('.claude/.jardis-backup/b/SKILL.md', 'x');
        $this->project->writeFile('src/own.php', 'x');
        self::assertSame(['src/own.php'], $this->visiblePaths(), 'only the project own file is left visible');
    }

    public function testLocalExcludesAgentsMdOnlyWhenPluginCreatedIt(): void
    {
        $this->project->writeFile('AGENTS.md', 'a');
        $this->project->writeFile('CLAUDE.md', 'c');

        $this->writeManifest(selfSet: ['AGENTS.md' => new SelfSetEntry(true), 'CLAUDE.md' => new SelfSetEntry(false)]);
        $this->sync(ProcessDocsMode::Local);
        $block = $this->blockOf($this->exclude());
        self::assertStringContainsString("\n/AGENTS.md\n", $block);
        self::assertStringNotContainsString('CLAUDE.md', $block);

        $this->writeManifest(selfSet: ['AGENTS.md' => new SelfSetEntry(false)]);
        $this->sync(ProcessDocsMode::Local);
        self::assertStringNotContainsString('AGENTS.md', $this->blockOf($this->exclude()));

        $this->writeManifest();
        $this->sync(ProcessDocsMode::Local);
        self::assertStringNotContainsString('AGENTS.md', $this->blockOf($this->exclude()), 'no note, no entry');
    }

    public function testForeignContentStaysByteEqualIncludingCrlf(): void
    {
        $this->writeManifest();
        $foreign = "# own rules\r\n*.log\r\n!keep.log\r\n";
        file_put_contents($this->excludePath(), $foreign);

        $this->sync(ProcessDocsMode::Local);
        $local = $this->exclude();
        self::assertStringStartsWith($foreign, $local);
        self::assertSame(0, substr_count(str_replace("\r\n", '', $local), "\n"), 'no bare LF in a CRLF file');

        $this->sync(ProcessDocsMode::Committed);
        $committed = $this->exclude();
        self::assertStringStartsWith($foreign, $committed);
        self::assertSame(
            $foreign . self::BEGIN . "\r\n.claude/.jardis-backup/\r\n" . self::END . "\r\n",
            $committed,
        );
    }

    public function testForeignContentAroundAnExistingBlockStaysByteEqual(): void
    {
        $this->writeManifest();
        $before = "first\n\n";
        $after = "\nlast\n*.tmp";
        file_put_contents(
            $this->excludePath(),
            $before . self::BEGIN . "\nstale-line\n" . self::END . "\n" . $after,
        );

        $this->sync(ProcessDocsMode::Committed);

        self::assertSame(
            $before . self::BEGIN . "\n.claude/.jardis-backup/\n" . self::END . "\n" . $after,
            $this->exclude(),
        );
    }

    public function testFileWithoutTrailingNewlineGetsASeparatorOnly(): void
    {
        file_put_contents($this->excludePath(), '*.log');

        $this->sync(ProcessDocsMode::Committed);

        self::assertSame(
            "*.log\n" . self::BEGIN . "\n.claude/.jardis-backup/\n" . self::END . "\n",
            $this->exclude(),
        );
    }

    public function testASecondRunLeavesTheFileUntouched(): void
    {
        $this->writeManifest();
        $this->sync(ProcessDocsMode::Local);
        $first = $this->exclude();
        $mtime = filemtime($this->excludePath());

        $this->sync(ProcessDocsMode::Local);

        self::assertSame($first, $this->exclude());
        self::assertSame($mtime, filemtime($this->excludePath()));
    }

    public function testSwitchToLocalWarnsWithTrackedFilesAndNeverUntracks(): void
    {
        $this->writeManifest();
        $this->project->writeFile('docs/vorhaben/plan.md', 'x');
        GitRepo::commitAll($this->project->root);

        $report = $this->sync(ProcessDocsMode::Local);

        self::assertCount(1, $report->warnings());
        $warning = $report->warnings()[0];
        self::assertStringContainsString('docs/vorhaben/plan.md', $warning);
        self::assertStringContainsString('.claude/skills/foo/SKILL.md', $warning);
        self::assertStringContainsString('.claude/skills/.jardis-managed.json', $warning);
        self::assertStringContainsString('never untracks', $warning);
        $tracked = GitRepo::run($this->project->root, 'ls-files');
        self::assertStringContainsString('docs/vorhaben/plan.md', $tracked, 'still tracked');
        self::assertStringContainsString('.claude/skills/foo/SKILL.md', $tracked);
        self::assertSame(self::BEGIN, strtok($this->blockOf($this->exclude()), "\n"), 'the block is written anyway');
    }

    public function testNoWarningWhenNothingExcludedIsTracked(): void
    {
        $this->project->writeFile('src/own.php', 'x');
        GitRepo::commitAll($this->project->root);
        $this->writeManifest();
        $this->project->writeFile('docs/vorhaben/plan.md', 'x');

        self::assertSame([], $this->sync(ProcessDocsMode::Local)->warnings());
    }

    public function testSwitchToCommittedRemovesOnlyModeEntriesAndKeepsBackupLine(): void
    {
        $this->writeManifest();
        $this->project->writeFile('src/own.php', 'x');
        $this->project->writeFile('.claude/.jardis-backup/b/SKILL.md', 'x');
        file_put_contents($this->excludePath(), "*.log\n");
        GitRepo::run($this->project->root, 'add', 'src/own.php');
        GitRepo::run($this->project->root, 'commit', '-q', '-m', 'own');
        $head = GitRepo::run($this->project->root, 'rev-parse', 'HEAD');
        $this->sync(ProcessDocsMode::Local);
        self::assertSame([], $this->visiblePaths(), 'local: everything the plugin created is hidden');

        $report = $this->sync(ProcessDocsMode::Committed);

        self::assertSame([], $report->warnings());
        self::assertSame(
            "*.log\n" . self::BEGIN . "\n.claude/.jardis-backup/\n" . self::END . "\n",
            $this->exclude(),
        );
        self::assertContains('.claude/skills/foo/SKILL.md', $this->visiblePaths(), 'untracked again, not committed');
        self::assertNotContains('.claude/.jardis-backup/b/SKILL.md', $this->visiblePaths());
        self::assertSame($head, GitRepo::run($this->project->root, 'rev-parse', 'HEAD'), 'nothing was committed');
        self::assertSame('', GitRepo::run($this->project->root, 'diff', '--cached', '--name-only'), 'nothing was staged');
    }

    public function testWithoutGitRepoWarnsAndWritesNothing(): void
    {
        $plain = new TempProject('dev-skills-nogit-');
        try {
            $before = TreeSnapshot::of($plain->root);

            foreach ([ProcessDocsMode::Committed, ProcessDocsMode::Local] as $mode) {
                $report = new InstallReport();
                AddonFactory::syncExcludeBlock($mode)($plain->root, $plain->path('vendor'), $report);

                self::assertCount(1, $report->warnings());
                self::assertStringContainsString('no Git repository at the project root', $report->warnings()[0]);
                self::assertStringContainsString('process-docs=' . $mode->value, $report->warnings()[0]);
            }
            self::assertSame($before, TreeSnapshot::of($plain->root));
        } finally {
            $plain->cleanup();
        }
    }

    public function testProjectBelowTheTopOfALargerRepositoryWarnsAndWritesNothing(): void
    {
        $inner = $this->project->mkdir('packages/app');
        $exclude = $this->exclude();

        foreach ([ProcessDocsMode::Committed, ProcessDocsMode::Local] as $mode) {
            $report = new InstallReport();
            AddonFactory::syncExcludeBlock($mode)($inner, $inner . '/vendor', $report);

            self::assertCount(1, $report->warnings());
            self::assertStringContainsString('inside the Git work tree', $report->warnings()[0]);
            self::assertStringContainsString(basename($this->project->root), $report->warnings()[0]);
            self::assertStringContainsString('process-docs=' . $mode->value, $report->warnings()[0]);
            self::assertSame($exclude, $this->exclude());
        }
        self::assertSame([], TreeSnapshot::of($inner));
    }

    public function testExcludeDirectoryInsteadOfFileWarnsAndRunContinues(): void
    {
        $exclude = $this->excludePath();
        if (is_file($exclude)) {
            unlink($exclude);
        }
        mkdir($exclude, 0o755, true);
        $after = $this->project->path('after.txt');

        $report = new InstallReport();
        $addons = new InstallAddons([
            'exclude-block' => AddonFactory::syncExcludeBlock(ProcessDocsMode::Local)->__invoke(...),
            'next' => static function () use ($after): void {
                file_put_contents($after, 'done');
            },
        ]);
        $addons($this->project->root, $this->project->path('vendor'), $report);

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('add-on "exclude-block"', $report->warnings()[0]);
        self::assertStringContainsString('not a regular file', $report->warnings()[0]);
        self::assertSame('done', file_get_contents($after));
        self::assertDirectoryExists($exclude);
    }

    public function testCorruptMarkersWarnAndLeaveTheFileUntouched(): void
    {
        $corrupt = "keep\n" . self::BEGIN . "\nno end\n";
        file_put_contents($this->excludePath(), $corrupt);

        $report = new InstallReport();
        (new InstallAddons(['exclude-block' => AddonFactory::syncExcludeBlock(ProcessDocsMode::Local)->__invoke(...)]))(
            $this->project->root,
            $this->project->path('vendor'),
            $report,
        );

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('corrupt managed-block markers', $report->warnings()[0]);
        self::assertSame($corrupt, $this->exclude());
    }

    public function testMissingInfoDirectoryIsCreated(): void
    {
        $info = $this->project->path('.git/info');
        (new Filesystem())->removeDirectory($info);

        $report = $this->sync(ProcessDocsMode::Committed);

        self::assertSame([], $report->warnings());
        self::assertFileExists($info . '/exclude');
    }

    public function testGitignoreStaysByteEqualInAllModes(): void
    {
        $this->writeManifest();
        $gitignore = "vendor/\r\n*.cache\n";
        $this->project->writeFile('.gitignore', $gitignore);

        foreach ([ProcessDocsMode::Committed, ProcessDocsMode::Local, ProcessDocsMode::Committed] as $mode) {
            $this->sync($mode);
            self::assertSame($gitignore, file_get_contents($this->project->path('.gitignore')));
        }
        self::assertSame(['.gitignore'], array_values(array_filter(
            GitRepo::visiblePaths($this->project->root),
            static fn (string $path): bool => str_contains($path, 'gitignore'),
        )));
    }

    private function sync(ProcessDocsMode $mode): InstallReport
    {
        $report = new InstallReport();
        AddonFactory::syncExcludeBlock($mode)($this->project->root, $this->project->path('vendor'), $report);

        return $report;
    }

    /**
     * @param array<string, SelfSetEntry> $selfSet
     */
    private function writeManifest(array $selfSet = []): void
    {
        $this->project->writeFile('.claude/skills/foo/SKILL.md', 'x');
        $this->project->writeFile('.claude/skills/bar/SKILL.md', 'x');
        $this->project->writeFile('.agents/skills/foo/SKILL.md', 'x');
        (new WriteManifest())($this->project->path(Manifest::FILE), new Manifest(
            Manifest::SCHEMA_VERSION,
            '1.4.0',
            [
                '.claude/skills/foo' => ['source' => 'jardis/dev-skills', 'sha256' => self::SHA],
                '.claude/skills/bar' => ['source' => 'jardis/dev-skills', 'sha256' => self::SHA],
                '.agents/skills/foo' => ['source' => 'jardis/dev-skills', 'sha256' => self::SHA],
            ],
            $selfSet,
        ));
    }

    private function excludePath(): string
    {
        return $this->project->path('.git/info/exclude');
    }

    private function exclude(): string
    {
        return (string) file_get_contents($this->excludePath());
    }

    private function blockOf(string $content): string
    {
        $start = strpos($content, self::BEGIN);
        $end = strpos($content, self::END);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($content, $start, $end + strlen(self::END) + 1 - $start);
    }

    /**
     * @return list<string> what `git status` shows, sorted
     */
    private function visiblePaths(): array
    {
        $paths = GitRepo::visiblePaths($this->project->root);
        sort($paths, SORT_STRING);

        return $paths;
    }
}
