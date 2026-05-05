<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Handler\Uninstall\RemoveJardisSkills;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RemoveJardisSkillsTest extends TestCase
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

    public function testRemovesPrefixedSkillsOnly(): void
    {
        foreach ([
            'adapter-cache', 'core-kernel', 'support-data', 'tools-definition',
            'schema-authoring', 'platform-implementation', 'rules-architecture',
        ] as $name) {
            $this->project->writeFile('.claude/skills/' . $name . '/SKILL.md', 'x');
        }
        $this->project->writeFile('.claude/skills/my-local/SKILL.md', 'local');
        $this->project->writeFile('.claude/skills/adapter-cache.backup/SKILL.md', 'backup');

        $removed = (new RemoveJardisSkills(new Filesystem()))($this->project->root);
        sort($removed);

        self::assertSame(
            ['adapter-cache', 'core-kernel', 'platform-implementation',
                'rules-architecture', 'schema-authoring', 'support-data', 'tools-definition'],
            $removed,
        );
        self::assertFileExists($this->project->path('.claude/skills/my-local/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/adapter-cache.backup/SKILL.md'));
    }

    public function testReturnsEmptyWhenSkillsDirMissing(): void
    {
        $removed = (new RemoveJardisSkills(new Filesystem()))($this->project->root);

        self::assertSame([], $removed);
    }
}
