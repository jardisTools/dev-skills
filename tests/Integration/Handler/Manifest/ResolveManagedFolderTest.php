<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Manifest;

use JardisTools\DevSkills\Handler\Manifest\ResolveManagedFolder;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ResolveManagedFolderTest extends TestCase
{
    private TempProject $project;
    private TempProject $foreign;
    private string $realRoot;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->foreign = new TempProject('dev-skills-foreign-');
        $this->realRoot = (string) realpath($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->foreign->cleanup();
    }

    public function testRelativeSkillFolderInBothSkillRootsResolvesToItsPath(): void
    {
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'a');
        $this->project->writeFile('.agents/skills/alpha/SKILL.md', 'a');

        self::assertSame($this->realRoot . '/.claude/skills/alpha', $this->resolve('.claude/skills/alpha'));
        self::assertSame($this->realRoot . '/.agents/skills/alpha', $this->resolve('.agents/skills/alpha'));
    }

    public function testNonExistentSafeKeyResolvesToEmptyString(): void
    {
        self::assertSame('', $this->resolve('.claude/skills/gone'));
        self::assertSame('', $this->resolve('.agents/skills/gone'));
    }

    public function testAbsolutePathIsRejectedEvenInsideTheProjectSkillFolder(): void
    {
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'a');
        $this->foreign->writeFile('victim/SKILL.md', 'v');

        self::assertNull($this->resolve($this->realRoot . '/.claude/skills/alpha'));
        self::assertNull($this->resolve($this->foreign->path('victim')));
    }

    public function testKeyWithParentSegmentIsRejected(): void
    {
        $this->project->writeFile('docs/keep/SKILL.md', 'd');
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'a');

        self::assertNull($this->resolve('.claude/skills/../../docs/keep'));
        self::assertNull($this->resolve('../' . basename($this->foreign->root) . '/victim'));
        self::assertNull($this->resolve('.claude/skills/alpha/../alpha'));
    }

    public function testDotAndDotDotAsNameAreRejected(): void
    {
        self::assertNull($this->resolve('.claude/skills/.'));
        self::assertNull($this->resolve('.claude/skills/..'));
        self::assertNull($this->resolve('.agents/skills/.'));
        self::assertNull($this->resolve('.agents/skills/..'));
    }

    public function testSubPathWithSlashIsRejected(): void
    {
        $this->project->writeFile('.claude/skills/alpha/sub/SKILL.md', 'a');

        self::assertNull($this->resolve('.claude/skills/alpha/sub'));
        self::assertNull($this->resolve('.claude/skills/nested/deep'));
        self::assertNull($this->resolve('.claude/skills/'));
        self::assertNull($this->resolve('.claude/skills/alpha/'));
    }

    public function testFolderOutsideTheSkillRootsOfTheProjectIsRejected(): void
    {
        $this->project->writeFile('docs/keep/SKILL.md', 'd');
        $this->project->writeFile('.claude/other/alpha/SKILL.md', 'o');
        $this->project->writeFile('skills/alpha/SKILL.md', 's');

        self::assertNull($this->resolve('docs/keep'));
        self::assertNull($this->resolve('.claude/other/alpha'));
        self::assertNull($this->resolve('skills/alpha'));
    }

    public function testSymlinkAsSkillFolderIsRejected(): void
    {
        $this->foreign->writeFile('victim/SKILL.md', 'v');
        $this->project->mkdir('.claude/skills');
        self::assertTrue(symlink($this->foreign->path('victim'), $this->project->path('.claude/skills/alpha')));

        self::assertNull($this->resolve('.claude/skills/alpha'));
    }

    public function testDanglingSymlinkIsRejectedNotTreatedAsMissing(): void
    {
        $this->project->mkdir('.claude/skills');
        self::assertTrue(symlink($this->foreign->path('nowhere'), $this->project->path('.claude/skills/alpha')));

        self::assertNull($this->resolve('.claude/skills/alpha'));
    }

    public function testFileInsteadOfFolderIsRejected(): void
    {
        $this->project->writeFile('.claude/skills/alpha', 'a file');

        self::assertNull($this->resolve('.claude/skills/alpha'));
    }

    private function resolve(string $key): ?string
    {
        return (new ResolveManagedFolder())($this->realRoot, $key);
    }
}
