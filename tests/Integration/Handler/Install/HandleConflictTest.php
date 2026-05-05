<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\HandleConflict;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class HandleConflictTest extends TestCase
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

    public function testMovesDirectoryToBackupSibling(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'old');
        $target = $this->project->path('.claude/skills/adapter-cache');

        $backup = (new HandleConflict(new Filesystem()))($target);

        self::assertSame($target . '.backup', $backup);
        self::assertDirectoryExists($backup);
        self::assertFileExists($backup . '/SKILL.md');
        self::assertDirectoryDoesNotExist($target);
    }

    public function testOverwritesExistingBackup(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/adapter-cache.backup/SKILL.md', 'stale');

        $target = $this->project->path('.claude/skills/adapter-cache');
        $backup = (new HandleConflict(new Filesystem()))($target);

        self::assertSame('new', file_get_contents($backup . '/SKILL.md'));
    }

    public function testThrowsWhenRenameFails(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'x');
        $target = $this->project->path('.claude/skills/adapter-cache');

        $handler = new HandleConflict(
            new Filesystem(),
            static fn (string $from, string $to): bool => false,
        );

        $this->expectException(InstallFailedException::class);
        $this->expectExceptionMessage('Could not move existing skill directory');
        $handler($target);
    }
}
