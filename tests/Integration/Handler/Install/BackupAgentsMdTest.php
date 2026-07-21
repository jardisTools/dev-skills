<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\BackupAgentsMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class BackupAgentsMdTest extends TestCase
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

    public function testMovesExistingFileToBackup(): void
    {
        $path = $this->project->writeFile('AGENTS.md', "user content\n");

        $backupPath = (new BackupAgentsMd())($path);

        self::assertSame($path . '.backup', $backupPath);
        self::assertFileExists($backupPath);
        self::assertFileDoesNotExist($path);
        self::assertSame("user content\n", file_get_contents($backupPath));
    }

    public function testReplacesStaleBackup(): void
    {
        $path = $this->project->writeFile('AGENTS.md', "new content\n");
        $this->project->writeFile('AGENTS.md.backup', "stale content\n");

        $backupPath = (new BackupAgentsMd())($path);

        self::assertSame("new content\n", file_get_contents($backupPath));
    }

    public function testThrowsWhenStaleBackupCannotBeRemoved(): void
    {
        $path = $this->project->writeFile('AGENTS.md', "new content\n");
        $this->project->writeFile('AGENTS.md.backup', "stale content\n");

        $handler = new BackupAgentsMd(
            unlink: static fn (string $p): bool => false,
        );

        $this->expectException(InstallFailedException::class);
        $this->expectExceptionMessage('stale AGENTS.md backup');
        $handler($path);
    }

    public function testThrowsWhenRenameFails(): void
    {
        $path = $this->project->writeFile('AGENTS.md', "content\n");

        $handler = new BackupAgentsMd(
            rename: static fn (string $from, string $to): bool => false,
        );

        $this->expectException(InstallFailedException::class);
        $this->expectExceptionMessage('Could not move existing AGENTS.md');
        $handler($path);
    }
}
