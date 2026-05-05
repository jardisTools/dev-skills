<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Discovery;

use JardisTools\DevSkills\Handler\Discovery\ScanVendor;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ScanVendorTest extends TestCase
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

    public function testFindsJardisSkills(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            "---\nname: adapter-cache\n---\n",
        );
        $this->project->writeFile(
            'vendor/jardissupport/data/.claude/skills/support-data/SKILL.md',
            "---\nname: support-data\n---\n",
        );

        $skills = (new ScanVendor())($this->project->path('vendor'));
        $names = array_map(static fn ($s) => $s->name, $skills);
        sort($names);

        self::assertSame(['adapter-cache', 'support-data'], $names);
    }

    public function testIgnoresNonJardisVendors(): void
    {
        $this->project->writeFile(
            'vendor/acme/foo/.claude/skills/acme-foo/SKILL.md',
            'irrelevant',
        );
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            "---\nname: adapter-cache\n---\n",
        );

        $skills = (new ScanVendor())($this->project->path('vendor'));

        self::assertCount(1, $skills);
        self::assertSame('adapter-cache', $skills[0]->name);
    }

    public function testIgnoresSkillDirsWithoutSkillMd(): void
    {
        $this->project->mkdir('vendor/jardisadapter/cache/.claude/skills/adapter-cache');

        $skills = (new ScanVendor())($this->project->path('vendor'));

        self::assertSame([], $skills);
    }

    public function testReturnsEmptyForMissingVendorDir(): void
    {
        $skills = (new ScanVendor())($this->project->path('nonexistent-vendor'));

        self::assertSame([], $skills);
    }

    public function testCapturesSourcePackage(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            'x',
        );

        $skills = (new ScanVendor())($this->project->path('vendor'));

        self::assertSame('jardisadapter/cache', $skills[0]->sourcePackage);
    }
}
