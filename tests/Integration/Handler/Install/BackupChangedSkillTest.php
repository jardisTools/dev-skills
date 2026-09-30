<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Data\StagedSkill;
use JardisTools\DevSkills\Handler\Install\BackupChangedSkill;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Handler\Install\FindFreeBackupDir;
use JardisTools\DevSkills\Handler\Manifest\ChecksumDirectory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class BackupChangedSkillTest extends TestCase
{
    private TempProject $project;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->now = new \DateTimeImmutable('2030-01-02 03:04:05');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testChangedManagedFolderIsCopiedToBackupRoot(): void
    {
        $staged = $this->stage('adapter-cache', 'managed', 'new');
        $manifest = $this->manifestFor($staged, 'managed');
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'edited locally');

        $backup = $this->backup($staged, $manifest);

        self::assertSame($this->backupRoot() . '/adapter-cache', $backup);
        self::assertSame('edited locally', file_get_contents($backup . '/SKILL.md'));
        // Copy, not move: the folder itself is left for the swap.
        self::assertFileExists($this->project->path('.claude/skills/adapter-cache/SKILL.md'));
    }

    public function testUnchangedManagedFolderIsNotBackedUp(): void
    {
        $staged = $this->stage('adapter-cache', 'managed', 'new');
        $manifest = $this->manifestFor($staged, 'managed');

        self::assertNull($this->backup($staged, $manifest));
        self::assertDirectoryDoesNotExist($this->backupRoot());
    }

    public function testRepeatedBackupGetsTimestampSuffixAndKeepsOlderBackups(): void
    {
        $staged = $this->stage('adapter-cache', 'managed', 'new');
        $manifest = $this->manifestFor($staged, 'managed');

        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'first edit');
        $first = $this->backup($staged, $manifest);
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'second edit');
        $second = $this->backup($staged, $manifest);
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'third edit');
        $third = $this->backup($staged, $manifest);

        self::assertSame($this->backupRoot() . '/adapter-cache', $first);
        self::assertSame($this->backupRoot() . '/adapter-cache-20300102T030405', $second);
        self::assertSame($this->backupRoot() . '/adapter-cache-20300102T030405-2', $third);
        self::assertSame('first edit', file_get_contents($first . '/SKILL.md'));
        self::assertSame('second edit', file_get_contents($second . '/SKILL.md'));
        self::assertSame('third edit', file_get_contents($third . '/SKILL.md'));
    }

    public function testFolderMissingFromHealthyManifestIsUserFolderAndBackedUp(): void
    {
        $staged = $this->stage('process-concept', 'user content', 'bundle content');

        $backup = $this->backup($staged, new Manifest(Manifest::SCHEMA_VERSION, '1.4.0'));

        self::assertNotNull($backup);
        self::assertSame('user content', file_get_contents($backup . '/SKILL.md'));
    }

    public function testLegacyBundleNameWithoutManifestIsBackedUpOnceEvenWhenIdentical(): void
    {
        $staged = $this->stage('rules-architecture', 'same', 'same');

        $backup = $this->backup($staged, null);

        self::assertNotNull($backup);
        self::assertSame('same', file_get_contents($backup . '/SKILL.md'));
    }

    public function testLegacyBundleNameIsNotBackedUpAgainOnceManifestExists(): void
    {
        $staged = $this->stage('rules-architecture', 'same', 'same');
        $manifest = $this->manifestFor($staged, 'same');

        self::assertNull($this->backup($staged, $manifest));
    }

    public function testUnknownNameWithoutManifestIsBackedUpOnlyWhenItDiffersFromTheNewContent(): void
    {
        $identical = $this->stage('adapter-cache', 'same', 'same');
        $different = $this->stage('adapter-logger', 'local', 'bundle');

        self::assertNull($this->backup($identical, null));
        self::assertNotNull($this->backup($different, null));
    }

    public function testMissingTargetNeedsNoBackup(): void
    {
        $staged = $this->stage('adapter-cache', 'x', 'y');
        (new Filesystem())->removeDirectory($staged->targetDir);

        self::assertNull($this->backup($staged, null));
    }

    private function stage(string $name, string $targetContent, string $newContent): StagedSkill
    {
        $target = $this->project->writeFile('.claude/skills/' . $name . '/SKILL.md', $targetContent);
        $this->project->writeFile('.claude/skills/.jardis-staging-' . $name . '/SKILL.md', $newContent);

        return new StagedSkill(
            new SkillDescriptor($name, $this->project->path('source/' . $name), 'jardisadapter/cache'),
            dirname($target),
            $this->project->path('.claude/skills/.jardis-staging-' . $name),
            '.claude/skills/' . $name,
        );
    }

    private function manifestFor(StagedSkill $staged, string $content): Manifest
    {
        $this->project->writeFile('recorded/' . $staged->skill->name . '/SKILL.md', $content);
        $sha = (new ChecksumDirectory())($this->project->path('recorded/' . $staged->skill->name));

        return new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', [
            $staged->manifestKey => ['source' => 'jardisadapter/cache', 'sha256' => $sha],
        ]);
    }

    private function backup(StagedSkill $staged, ?Manifest $manifest): ?string
    {
        $handler = new BackupChangedSkill(
            (new CopySkill(new Filesystem()))->__invoke(...),
            (new ChecksumDirectory())->__invoke(...),
            (new FindFreeBackupDir(fn (): \DateTimeImmutable => $this->now))->__invoke(...),
        );

        return $handler($staged, $manifest, $this->backupRoot());
    }

    private function backupRoot(): string
    {
        return $this->project->path('.claude/.jardis-backup');
    }
}
