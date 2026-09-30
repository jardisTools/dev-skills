<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Support;

use JardisTools\DevSkills\Handler\Support\IsPathBehindLink;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class IsPathBehindLinkTest extends TestCase
{
    private TempProject $project;
    private TempProject $outside;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->outside = new TempProject('dev-skills-outside-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->outside->cleanup();
    }

    public function testRegularFileIsNotBehindALink(): void
    {
        $this->project->writeFile('.gemini/settings.json', '{}');

        self::assertFalse($this->check('.gemini/settings.json'));
    }

    public function testLinkAtTheTargetInsideTheProjectIsBehindALink(): void
    {
        $this->project->writeFile('notes.md', 'notes');
        self::assertTrue(symlink('notes.md', $this->project->path('CLAUDE.md')));

        self::assertTrue($this->check('CLAUDE.md'));
    }

    public function testLinkAtTheTargetLeadingOutIsBehindALink(): void
    {
        $this->outside->writeFile('notes.md', 'notes');
        self::assertTrue(symlink($this->outside->path('notes.md'), $this->project->path('CLAUDE.md')));

        self::assertTrue($this->check('CLAUDE.md'));
    }

    public function testDanglingLinkIsBehindALink(): void
    {
        self::assertTrue(symlink($this->project->path('gone.md'), $this->project->path('CLAUDE.md')));

        self::assertTrue($this->check('CLAUDE.md'));
    }

    public function testFolderLinkInsideTheProjectIsBehindALink(): void
    {
        $this->project->writeFile('real/settings.json', '{}');
        self::assertTrue(symlink('real', $this->project->path('.gemini')));

        self::assertTrue($this->check('.gemini/settings.json'));
        self::assertTrue($this->check('.gemini/agents/reviewer.md'), 'a missing file behind the link as well');
    }

    public function testFolderLinkLeadingOutIsBehindALink(): void
    {
        $this->outside->writeFile('settings.json', '{}');
        self::assertTrue(symlink($this->outside->root, $this->project->path('.gemini')));

        self::assertTrue($this->check('.gemini/settings.json'));
        self::assertTrue($this->check('.gemini/agents'));
    }

    public function testLinkDeeperOnTheWayIsBehindALink(): void
    {
        $this->project->mkdir('.codex');
        self::assertTrue(symlink($this->outside->root, $this->project->path('.codex/agents')));

        self::assertTrue($this->check('.codex/agents/reviewer.toml'));
    }

    public function testMissingPathWithoutALinkIsNotBehindALink(): void
    {
        self::assertFalse($this->check('.gemini/settings.json'));
        self::assertFalse($this->check('.codex/agents/reviewer.toml'));

        $this->project->mkdir('.codex');
        self::assertFalse($this->check('.codex/agents/reviewer.toml'));
    }

    public function testPathOutsideTheProjectIsBehindALink(): void
    {
        self::assertTrue((new IsPathBehindLink())($this->project->root, $this->outside->path('file.md')));
    }

    public function testParentSegmentIsBehindALinkBecauseAnEarlierLinkCannotBeTold(): void
    {
        $this->project->mkdir('real');
        self::assertTrue(symlink($this->outside->root, $this->project->path('real/out')));

        self::assertTrue($this->check('real/out/../file.md'));
    }

    public function testLinkInTheProjectRootItselfDoesNotCount(): void
    {
        $this->project->mkdir('real');
        $alias = $this->outside->path('alias');
        self::assertTrue(symlink($this->project->root, $alias));

        self::assertFalse((new IsPathBehindLink())($alias, $alias . '/real/file.md'));
    }

    private function check(string $relative): bool
    {
        return (new IsPathBehindLink())($this->project->root, $this->project->path($relative));
    }
}
