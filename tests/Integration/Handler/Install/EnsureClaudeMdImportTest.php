<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class EnsureClaudeMdImportTest extends TestCase
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

    public function testCreatesClaudeMdWithManagedImportBlockWhenMissing(): void
    {
        $report = $this->ensure();

        self::assertSame(
            AnalyzeAgentsMd::HEADER . "\n@AGENTS.md\n" . AnalyzeAgentsMd::FOOTER . "\n",
            file_get_contents($this->project->path('CLAUDE.md')),
        );
        self::assertSame([], $report->warnings());
        $noted = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest?->selfSet['CLAUDE.md'] ?? null;
        self::assertNotNull($noted);
        self::assertTrue($noted->fileCreated);
    }

    public function testAppendsBlockAndKeepsRestByteEqualIncludingCrlf(): void
    {
        $original = "# Project\r\n\r\nRules for agents.\r\n";
        $this->project->writeFile('CLAUDE.md', $original);

        $this->ensure();

        $content = (string) file_get_contents($this->project->path('CLAUDE.md'));
        self::assertStringStartsWith($original, $content);
        self::assertSame(
            "\r\n" . AnalyzeAgentsMd::HEADER . "\r\n@AGENTS.md\r\n" . AnalyzeAgentsMd::FOOTER . "\r\n",
            substr($content, strlen($original)),
        );
        self::assertSame(0, substr_count(str_replace("\r\n", '', $content), "\n"), 'no bare LF in a CRLF file');
        $noted = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest?->selfSet['CLAUDE.md'] ?? null;
        self::assertNotNull($noted);
        self::assertFalse($noted->fileCreated);
    }

    public function testLeavesFileUntouchedWhenImportStandsOutsideBlock(): void
    {
        $original = "# Project\n\n@AGENTS.md\n\nMore.\n";
        $this->project->writeFile('CLAUDE.md', $original);

        $report = $this->ensure();

        self::assertSame($original, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertSame([], $report->warnings());
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE), 'nothing was set, nothing is noted');
    }

    public function testWarnsAndLeavesFileUntouchedOnCorruptMarkers(): void
    {
        $original = "# Project\n" . AnalyzeAgentsMd::HEADER . "\n@AGENTS.md\n";
        $this->project->writeFile('CLAUDE.md', $original);

        $report = $this->ensure();

        self::assertSame($original, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('corrupt managed-block markers', $report->warnings()[0]);
    }

    public function testCreatesRootFileEvenWhenDotClaudeClaudeMdExists(): void
    {
        $inner = "# Inner rules\n";
        $this->project->writeFile('.claude/CLAUDE.md', $inner);

        $this->ensure();

        self::assertFileExists($this->project->path('CLAUDE.md'));
        self::assertStringContainsString('@AGENTS.md', (string) file_get_contents($this->project->path('CLAUDE.md')));
        self::assertSame($inner, file_get_contents($this->project->path('.claude/CLAUDE.md')));
    }

    public function testSymlinkToAgentsMdGetsNoBlockAndWarns(): void
    {
        $agents = "# Agents\n";
        $this->project->writeFile('AGENTS.md', $agents);
        self::assertTrue(symlink('AGENTS.md', $this->project->path('CLAUDE.md')));

        $report = $this->ensure();

        self::assertTrue(is_link($this->project->path('CLAUDE.md')));
        self::assertSame($agents, file_get_contents($this->project->path('AGENTS.md')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('link to AGENTS.md', $report->warnings()[0]);
    }

    public function testLinkLeadingOutOfTheProjectIsNotWrittenThrough(): void
    {
        $outside = new TempProject('dev-skills-outside-');
        try {
            $outside->writeFile('notes.md', "# Notes\n");
            self::assertTrue(symlink($outside->path('notes.md'), $this->project->path('CLAUDE.md')));

            $report = $this->ensure();

            self::assertSame("# Notes\n", file_get_contents($outside->path('notes.md')));
            self::assertCount(1, $report->warnings());
            self::assertStringContainsString('leads out of the project', $report->warnings()[0]);
        } finally {
            $outside->cleanup();
        }
    }

    public function testLinkToAnotherFileInsideTheProjectIsNotWrittenThrough(): void
    {
        $this->project->writeFile('notes.md', "# Notes\n");
        self::assertTrue(symlink('notes.md', $this->project->path('CLAUDE.md')));

        $report = $this->ensure();

        self::assertSame("# Notes\n", file_get_contents($this->project->path('notes.md')));
        self::assertTrue(is_link($this->project->path('CLAUDE.md')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('CLAUDE.md is a link; the file is unchanged.', $report->warnings()[0]);
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->ensure();
        $first = (string) file_get_contents($this->project->path('CLAUDE.md'));

        $report = $this->ensure();

        self::assertSame($first, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertSame([], $report->warnings());
    }

    private function ensure(): InstallReport
    {
        $report = new InstallReport();
        AddonFactory::ensureClaudeMd()($this->project->root, $this->project->path('vendor'), $report);

        return $report;
    }

    public function testAgentsMdLinkedToClaudeMdGetsNoImportBlock(): void
    {
        $claude = "# Claude rules\n";
        $this->project->writeFile('CLAUDE.md', $claude);
        self::assertTrue(symlink('CLAUDE.md', $this->project->path('AGENTS.md')));

        $report = $this->ensure();

        self::assertSame($claude, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertFalse(is_link($this->project->path('CLAUDE.md')));
        self::assertTrue(is_link($this->project->path('AGENTS.md')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('AGENTS.md is a link to CLAUDE.md', $report->warnings()[0]);
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
    }
}
