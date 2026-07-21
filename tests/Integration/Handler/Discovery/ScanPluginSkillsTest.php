<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Discovery;

use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ScanPluginSkillsTest extends TestCase
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

    public function testFindsCrossPackageSkills(): void
    {
        $this->project->writeFile('skills/plan-requirements/SKILL.md', 'x');
        $this->project->writeFile('skills/platform-usage/SKILL.md', 'y');

        $skills = (new ScanPluginSkills())($this->project->root);
        $names = array_map(static fn ($s) => $s->name, $skills);
        sort($names);

        self::assertSame(['plan-requirements', 'platform-usage'], $names);
    }

    public function testSourcePackageIsPlugin(): void
    {
        $this->project->writeFile('skills/plan-requirements/SKILL.md', 'x');

        $skills = (new ScanPluginSkills())($this->project->root);

        self::assertSame('jardis/dev-skills', $skills[0]->sourcePackage);
    }

    public function testSkipsBackupSkillDirectories(): void
    {
        $this->project->writeFile('skills/platform-usage/SKILL.md', 'x');
        $this->project->writeFile('skills/platform-usage.backup/SKILL.md', 'stale');

        $skills = (new ScanPluginSkills())($this->project->root);
        $names = array_map(static fn ($s) => $s->name, $skills);

        self::assertSame(['platform-usage'], $names);
    }

    public function testReturnsEmptyWhenSkillsDirMissing(): void
    {
        $skills = (new ScanPluginSkills())($this->project->root);

        self::assertSame([], $skills);
    }
}
