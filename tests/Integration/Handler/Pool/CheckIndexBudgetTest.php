<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckIndexBudget;
use JardisTools\DevSkills\Handler\Pool\LoadPool;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckIndexBudgetTest extends TestCase
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

    public function testIndexUnder10240BytesPasses(): void
    {
        $this->project->writeFile('.claude/wissen/INDEX.md', str_repeat('i', 10239));
        $pool = (new LoadPool())($this->project->root);

        self::assertSame(10239, $pool['index']?->bytes);
        self::assertSame([], (new CheckIndexBudget())($pool['index']));
    }

    public function testIndexAt10240BytesFails(): void
    {
        $pool = PoolFixture::load($this->project, 'red-index-budget');

        self::assertSame(10240, $pool['index']?->bytes);

        $violations = (new CheckIndexBudget())($pool['index']);

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_INDEX_BYTES, $violations[0]->rule);
        self::assertSame('.claude/wissen/INDEX.md', $violations[0]->file);
    }

    public function testMissingIndexHasNothingToMeasure(): void
    {
        self::assertSame([], (new CheckIndexBudget())(null));
    }
}
