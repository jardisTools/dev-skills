<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\SelfSetEntry;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RemoveReviewerShellsTest extends TestCase
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

    public function testRemovesListedShellsAndTheirEmptyFolders(): void
    {
        $this->project->writeFile('.codex/agents/alpha.toml', 'x');

        $report = $this->remove(['.codex/agents/alpha.toml' => true]);

        self::assertSame([], $report->warnings());
        self::assertDirectoryDoesNotExist($this->project->path('.codex'));
    }

    public function testKeepsLinksFoldersAndKeysThatAreNoShellPaths(): void
    {
        $outside = new TempProject('dev-skills-outside-');
        try {
            $outside->writeFile('target.md', 'outside');
            $this->project->mkdir('.claude/agents');
            symlink($outside->path('target.md'), $this->project->path('.claude/agents/linked.md'));
            $this->project->mkdir('.cursor/agents/folder.md');
            $this->project->writeFile('notes.txt', 'mine');
            $this->project->writeFile('.claude/agents/../mine.md', 'mine');

            $this->remove([
                '.claude/agents/linked.md' => true,
                '.cursor/agents/folder.md' => true,
                'notes.txt' => true,
                '.claude/agents/../mine.md' => true,
            ]);

            self::assertSame('outside', file_get_contents($outside->path('target.md')));
            self::assertTrue(is_link($this->project->path('.claude/agents/linked.md')));
            self::assertDirectoryExists($this->project->path('.cursor/agents/folder.md'));
            self::assertSame('mine', file_get_contents($this->project->path('notes.txt')));
            self::assertSame('mine', file_get_contents($this->project->path('.claude/mine.md')));
        } finally {
            $outside->cleanup();
        }
    }

    public function testEntriesNotCreatedByThePluginAreKept(): void
    {
        $this->project->writeFile('.gemini/agents/beta.md', 'mine');

        $this->remove(['.gemini/agents/beta.md' => false]);

        self::assertSame('mine', file_get_contents($this->project->path('.gemini/agents/beta.md')));
    }

    public function testWithoutManifestNothingHappens(): void
    {
        $this->project->writeFile('.codex/agents/alpha.toml', 'x');

        AddonFactory::removeReviewerShells()($this->project->root, null, new UninstallReport());

        self::assertFileExists($this->project->path('.codex/agents/alpha.toml'));
    }

    /**
     * @param array<string, bool> $selfSet path => fileCreated
     */
    private function remove(array $selfSet): UninstallReport
    {
        $report = new UninstallReport();
        $entries = array_map(static fn (bool $created): SelfSetEntry => new SelfSetEntry($created), $selfSet);
        AddonFactory::removeReviewerShells()(
            $this->project->root,
            new Manifest(Manifest::SCHEMA_VERSION, '1.0.0', [], $entries),
            $report,
        );

        return $report;
    }
}
