<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckSupersession;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckSupersessionTest extends TestCase
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

    public function testMissingPageListedInErsetztPasses(): void
    {
        $pool = PoolFixture::load($this->project, 'green');

        self::assertFileDoesNotExist($this->project->path('.claude/wissen/old-alpha.md'));
        self::assertSame([], (new CheckSupersession())($pool['files']));
    }

    public function testMissingPageUnlistedFails(): void
    {
        $pool = PoolFixture::load($this->project, 'red-supersession');
        $file = '.claude/wissen/edge-page.md';

        $violations = (new CheckSupersession())($pool['files']);

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_EDGE_DEAD, $violations[0]->rule);
        self::assertSame($file, $violations[0]->file);
        self::assertSame(PoolFixture::lineOf($this->project, $file, '[[ghost-page]]'), $violations[0]->line);
        self::assertStringContainsString('ghost-page', $violations[0]->message);
    }
}
