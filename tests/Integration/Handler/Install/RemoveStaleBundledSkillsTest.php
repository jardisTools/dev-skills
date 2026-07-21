<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Handler\Install\RemoveStaleBundledSkills;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RemoveStaleBundledSkillsTest extends TestCase
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

    public function testRemovesExistingBundledSkillDirectory(): void
    {
        $this->project->writeFile('.claude/skills/plan-requirements/SKILL.md', 'bundled');

        $removed = (new RemoveStaleBundledSkills(new Filesystem()))(
            ['plan-requirements'],
            $this->project->root,
        );

        self::assertSame(['plan-requirements'], $removed);
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/plan-requirements'));
    }

    public function testSkipsNamesThatAreNotOnDisk(): void
    {
        $removed = (new RemoveStaleBundledSkills(new Filesystem()))(
            ['plan-requirements'],
            $this->project->root,
        );

        self::assertSame([], $removed);
    }

    public function testRemovesEvenWhenUserModifiedTheSkill(): void
    {
        $this->project->writeFile(
            '.claude/skills/plan-requirements/SKILL.md',
            '# user edited this!',
        );
        $this->project->writeFile(
            '.claude/skills/plan-requirements/notes.md',
            'my additional file',
        );

        $removed = (new RemoveStaleBundledSkills(new Filesystem()))(
            ['plan-requirements'],
            $this->project->root,
        );

        self::assertSame(['plan-requirements'], $removed);
        self::assertFileDoesNotExist(
            $this->project->path('.claude/skills/plan-requirements/notes.md'),
        );
    }

    public function testReturnsOnlyActuallyRemovedNames(): void
    {
        $this->project->writeFile('.claude/skills/platform-usage/SKILL.md', 'x');

        $removed = (new RemoveStaleBundledSkills(new Filesystem()))(
            ['plan-requirements', 'platform-usage', 'rules-patterns'],
            $this->project->root,
        );

        self::assertSame(['platform-usage'], $removed);
    }

    public function testEmptyInputIsNoOp(): void
    {
        $removed = (new RemoveStaleBundledSkills(new Filesystem()))(
            [],
            $this->project->root,
        );

        self::assertSame([], $removed);
    }
}
