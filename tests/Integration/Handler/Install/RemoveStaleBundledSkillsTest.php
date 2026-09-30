<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\StaleRemovalResult;
use JardisTools\DevSkills\Handler\Install\BackupFolder;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Handler\Install\FindFreeBackupDir;
use JardisTools\DevSkills\Handler\Install\RemoveStaleBundledSkills;
use JardisTools\DevSkills\Handler\Manifest\ChecksumDirectory;
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

    public function testRemovesManifestPathsInBothFoldersWithoutBackupWhenUnchanged(): void
    {
        $manifest = $this->installAlpha();

        $result = $this->remove(['.claude/skills/alpha', '.agents/skills/alpha'], $manifest);

        self::assertSame(['alpha'], $result->removed);
        self::assertSame([], $result->backups);
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/alpha'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/alpha'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/.jardis-backup'));
    }

    public function testLocallyChangedFolderIsBackedUpBeforeRemoval(): void
    {
        $manifest = $this->installAlpha();
        $this->project->writeFile('.claude/skills/alpha/notes.md', 'my notes');

        $result = $this->remove(['.claude/skills/alpha', '.agents/skills/alpha'], $manifest);

        self::assertCount(1, $result->backups);
        self::assertSame('alpha', $result->backups[0]['skill']);
        self::assertSame('my notes', file_get_contents($this->project->path('.claude/.jardis-backup/alpha/notes.md')));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/alpha'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/alpha'));
    }

    public function testOnlyPathsGivenAreTouchedNeverNamesFromElsewhere(): void
    {
        $manifest = $this->installAlpha();
        $this->project->writeFile('.claude/skills/user-own/SKILL.md', 'mine');

        $this->remove(['.claude/skills/alpha'], $manifest);

        self::assertDirectoryExists($this->project->path('.agents/skills/alpha'));
        self::assertFileExists($this->project->path('.claude/skills/user-own/SKILL.md'));
    }

    public function testSkipsPathsNotOnDiskAndKeysNotInManifest(): void
    {
        $manifest = $this->installAlpha();

        $result = $this->remove(['.claude/skills/gone', '.claude/skills/not-listed'], $manifest);

        self::assertSame([], $result->removed);
    }

    public function testKeysWithParentSegmentsAreIgnored(): void
    {
        $outside = new TempProject('dev-skills-outside-');
        $outside->writeFile('victim/SKILL.md', 'keep');
        $key = '../' . basename($outside->root) . '/victim';
        $manifest = new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', [
            $key => ['source' => 'jardis/dev-skills', 'sha256' => 'x'],
        ]);

        $result = $this->remove([$key], $manifest);

        self::assertSame([], $result->removed);
        self::assertFileExists($outside->path('victim/SKILL.md'));
        $outside->cleanup();
    }

    public function testWithoutManifestNothingIsRemoved(): void
    {
        $this->installAlpha();

        $result = $this->remove(['.claude/skills/alpha'], null);

        self::assertSame([], $result->removed);
        self::assertDirectoryExists($this->project->path('.claude/skills/alpha'));
    }

    private function installAlpha(): Manifest
    {
        $checksum = new ChecksumDirectory();
        $entries = [];
        foreach (['.claude/skills/alpha', '.agents/skills/alpha'] as $key) {
            $this->project->writeFile($key . '/SKILL.md', 'alpha');
            $entries[$key] = ['source' => 'jardis/dev-skills', 'sha256' => $checksum($this->project->path($key))];
        }
        $entries['.claude/skills/not-listed'] = ['source' => 'jardis/dev-skills', 'sha256' => 'x'];

        return new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', $entries);
    }

    /**
     * @param list<string> $keys
     */
    private function remove(array $keys, ?Manifest $manifest): StaleRemovalResult
    {
        $fs = new Filesystem();
        $backupFolder = new BackupFolder(
            (new CopySkill($fs))->__invoke(...),
            (new FindFreeBackupDir(static fn (): \DateTimeImmutable => new \DateTimeImmutable()))->__invoke(...),
        );

        return (new RemoveStaleBundledSkills($fs, (new ChecksumDirectory())->__invoke(...), $backupFolder->__invoke(...)))(
            $keys,
            $manifest,
            $this->project->root,
            $this->project->path('.claude/.jardis-backup'),
        );
    }
}
