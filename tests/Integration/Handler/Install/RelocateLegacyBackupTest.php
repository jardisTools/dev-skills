<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Data\StagedSkill;
use JardisTools\DevSkills\Handler\Install\FindFreeBackupDir;
use JardisTools\DevSkills\Handler\Install\RelocateLegacyBackup;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RelocateLegacyBackupTest extends TestCase
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

    public function testMovesLegacyBackupSiblingIntoBackupRoot(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'current');
        $this->project->writeFile('.claude/skills/adapter-cache.backup/SKILL.md', 'local edit');

        $moved = $this->relocate();

        self::assertSame($this->backupRoot() . '/adapter-cache', $moved);
        self::assertSame('local edit', file_get_contents($moved . '/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/adapter-cache.backup'));
        self::assertSame('current', file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')));
    }

    public function testKeepsAnExistingBackupOfTheSameName(): void
    {
        $this->project->writeFile('.claude/.jardis-backup/adapter-cache/SKILL.md', 'older');
        $this->project->writeFile('.claude/skills/adapter-cache.backup/SKILL.md', 'legacy');

        $moved = $this->relocate();

        self::assertNotSame($this->backupRoot() . '/adapter-cache', $moved);
        self::assertSame('legacy', file_get_contents($moved . '/SKILL.md'));
        self::assertSame('older', file_get_contents($this->backupRoot() . '/adapter-cache/SKILL.md'));
    }

    public function testWithoutLegacyBackupNothingHappens(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'current');

        self::assertNull($this->relocate());
        self::assertDirectoryDoesNotExist($this->backupRoot());
    }

    private function relocate(): ?string
    {
        $staged = new StagedSkill(
            new SkillDescriptor('adapter-cache', $this->project->path('source/adapter-cache'), 'jardisadapter/cache'),
            $this->project->path('.claude/skills/adapter-cache'),
            $this->project->path('.claude/skills/.jardis-staging-adapter-cache'),
            '.claude/skills/adapter-cache',
        );
        $find = new FindFreeBackupDir(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2030-01-02 03:04:05'));

        return (new RelocateLegacyBackup($find->__invoke(...)))($staged, $this->backupRoot());
    }

    private function backupRoot(): string
    {
        return $this->project->path('.claude/.jardis-backup');
    }
}
