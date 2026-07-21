<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Handler\Install\HandleConflict;
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

    public function testCopiesSkillIntoProjectSkillsDir(): void
    {
        $this->project->writeFile('source/adapter-cache/SKILL.md', 'cache-content');
        $this->project->writeFile('source/adapter-cache/nested/extra.md', 'extra');

        $descriptor = new SkillDescriptor(
            name: 'adapter-cache',
            sourceDir: $this->project->path('source/adapter-cache'),
            sourcePackage: 'jardisadapter/cache',
        );

        $fs = new Filesystem();
        $copy = new CopySkill($fs, (new HandleConflict($fs))->__invoke(...));
        $backup = $copy($descriptor, $this->project->root);

        self::assertNull($backup);
        self::assertSame(
            'cache-content',
            file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')),
        );
        self::assertSame(
            'extra',
            file_get_contents($this->project->path('.claude/skills/adapter-cache/nested/extra.md')),
        );
    }

    public function testMovesExistingTargetToBackup(): void
    {
        $this->project->writeFile('source/adapter-cache/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'old');

        $descriptor = new SkillDescriptor(
            name: 'adapter-cache',
            sourceDir: $this->project->path('source/adapter-cache'),
            sourcePackage: 'jardisadapter/cache',
        );

        $fs = new Filesystem();
        $copy = new CopySkill($fs, (new HandleConflict($fs))->__invoke(...));
        $backup = $copy($descriptor, $this->project->root);

        self::assertNotNull($backup);
        self::assertSame(
            'old',
            file_get_contents($this->project->path('.claude/skills/adapter-cache.backup/SKILL.md')),
        );
        self::assertSame(
            'new',
            file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')),
        );
    }
}
