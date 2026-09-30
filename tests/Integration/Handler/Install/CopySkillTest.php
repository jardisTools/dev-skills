<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CopySkillTest extends TestCase
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

    public function testCopiesSkillTreeIntoDestination(): void
    {
        $this->project->writeFile('source/adapter-cache/SKILL.md', 'cache-content');
        $this->project->writeFile('source/adapter-cache/nested/extra.md', 'extra');

        (new CopySkill(new Filesystem()))($this->descriptor(), $this->project->path('.claude/skills/adapter-cache'));

        self::assertSame(
            'cache-content',
            file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')),
        );
        self::assertSame(
            'extra',
            file_get_contents($this->project->path('.claude/skills/adapter-cache/nested/extra.md')),
        );
    }

    public function testCopiesIntoAnyDestinationAndCreatesParents(): void
    {
        $this->project->writeFile('source/adapter-cache/SKILL.md', 'cache-content');

        (new CopySkill(new Filesystem()))($this->descriptor(), $this->project->path('.agents/skills/adapter-cache'));

        self::assertSame(
            'cache-content',
            file_get_contents($this->project->path('.agents/skills/adapter-cache/SKILL.md')),
        );
    }

    public function testLeavesNoBackupSiblingWhenDestinationExists(): void
    {
        $this->project->writeFile('source/adapter-cache/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'old');

        (new CopySkill(new Filesystem()))($this->descriptor(), $this->project->path('.claude/skills/adapter-cache'));

        self::assertSame('new', file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')));
        self::assertSame(['adapter-cache'], array_map('basename', glob($this->project->path('.claude/skills/*')) ?: []));
    }

    public function testFailsOnUncopyableEntry(): void
    {
        $this->project->writeFile('source/adapter-cache/SKILL.md', 'x');
        // Fixture only: a dangling link cannot be copied, also when running as root.
        symlink($this->project->path('source/does-not-exist'), $this->project->path('source/adapter-cache/broken'));

        $this->expectException(InstallFailedException::class);
        (new CopySkill(new Filesystem()))($this->descriptor(), $this->project->path('dest/adapter-cache'));
    }

    private function descriptor(): SkillDescriptor
    {
        return new SkillDescriptor(
            name: 'adapter-cache',
            sourceDir: $this->project->path('source/adapter-cache'),
            sourcePackage: 'jardisadapter/cache',
        );
    }
}
