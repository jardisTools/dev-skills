<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckLinks;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckLinksTest extends TestCase
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

    public function testWikiAndRelativeLinksResolve(): void
    {
        $pool = PoolFixture::load($this->project, 'green');

        self::assertSame([], (new CheckLinks())($this->project->root, $pool['files']));
    }

    public function testDeadLinkFails(): void
    {
        $pool = PoolFixture::load($this->project, 'red-links');
        $file = '.claude/wissen/links-page.md';

        $violations = (new CheckLinks())($this->project->root, $pool['files']);

        self::assertCount(3, $violations);
        foreach ($violations as $violation) {
            self::assertSame(PoolViolation::RULE_LINK_DEAD, $violation->rule);
            self::assertSame($file, $violation->file);
        }
        $lines = array_map(static fn (PoolViolation $v): int => $v->line, $violations);
        sort($lines);
        self::assertSame(
            [
                PoolFixture::lineOf($this->project, $file, 'gone-page.md'),
                PoolFixture::lineOf($this->project, $file, 'elsewhere.md'),
                PoolFixture::lineOf($this->project, $file, 'kind::target'),
            ],
            $lines,
        );
    }
}
