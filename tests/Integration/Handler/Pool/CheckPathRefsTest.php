<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckPathRefs;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckPathRefsTest extends TestCase
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

    public function testExistingPathAndLinePass(): void
    {
        $pool = PoolFixture::load($this->project, 'green');

        self::assertSame([], (new CheckPathRefs())($this->project->root, true, $pool['files']));
    }

    public function testMissingPathFails(): void
    {
        $pool = PoolFixture::load($this->project, 'red-pathrefs');
        $file = '.claude/wissen/refs-page.md';

        $violations = (new CheckPathRefs())($this->project->root, true, $pool['files']);

        self::assertCount(2, $violations);
        self::assertSame(PoolViolation::RULE_PATH_DEAD, $violations[0]->rule);
        self::assertSame(PoolFixture::lineOf($this->project, $file, 'absent.conf'), $violations[0]->line);
        self::assertSame(PoolViolation::RULE_LINE_DEAD, $violations[1]->rule);
        self::assertSame(PoolFixture::lineOf($this->project, $file, 'widget.conf:99'), $violations[1]->line);
        self::assertSame($file, $violations[1]->file);
    }

    public function testSkippedWithoutKnownRepoRoot(): void
    {
        $pool = PoolFixture::load($this->project, 'red-pathrefs');

        self::assertSame([], (new CheckPathRefs())($this->project->root, false, $pool['files']));

        $this->project->mkdir('.git');

        self::assertCount(2, (new CheckPathRefs())($this->project->root, false, $pool['files']));
    }
}
