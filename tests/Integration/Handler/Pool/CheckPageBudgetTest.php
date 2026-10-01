<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckPageBudget;
use JardisTools\DevSkills\Handler\Pool\LoadPool;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckPageBudgetTest extends TestCase
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

    public function testPageAtLimitPasses(): void
    {
        // exactly 160 lines and exactly 16384 bytes
        $content = str_repeat(str_repeat('a', 101) . "\n", 159) . str_repeat('b', 16384 - 159 * 102 - 1) . "\n";
        self::assertSame(16384, strlen($content));
        $this->project->writeFile('.claude/wissen/limit.md', $content);
        $pool = (new LoadPool())($this->project->root);

        self::assertSame(160, $pool['pages'][0]->lineCount);
        self::assertSame([], (new CheckPageBudget())($pool['pages']));
    }

    public function testPageOver16384BytesFails(): void
    {
        $pool = PoolFixture::load($this->project, 'red-page-budget');
        $pages = array_values(array_filter($pool['pages'], static fn ($p): bool => $p->id === 'too-big'));

        $violations = (new CheckPageBudget())($pages);

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_PAGE_BYTES, $violations[0]->rule);
        self::assertSame('.claude/wissen/too-big.md', $violations[0]->file);
        self::assertStringContainsString('16385', $violations[0]->message);
    }

    public function testPageOver160LinesFails(): void
    {
        $pool = PoolFixture::load($this->project, 'red-page-budget');
        $pages = array_values(array_filter($pool['pages'], static fn ($p): bool => $p->id === 'too-long'));

        $violations = (new CheckPageBudget())($pages);

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_PAGE_LINES, $violations[0]->rule);
        self::assertSame(161, $violations[0]->line);
        self::assertStringContainsString('161 lines', $violations[0]->message);
    }
}
