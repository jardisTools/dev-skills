<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckPlanBudget;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckPlanBudgetTest extends TestCase
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

    /**
     * @return list<PoolViolation>
     */
    private function check(string $case, ?string $folder = null): array
    {
        return (new CheckPlanBudget())(PoolFixture::loadVorhaben($this->project, $case, $folder));
    }

    public function testPlanFileOver150LinesFails(): void
    {
        $violations = $this->check('vorhaben-red-plan', 'stage-plan-long');

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_PLAN_LINES, $violations[0]->rule);
        self::assertSame('docs/vorhaben/stage-plan-long/PLAN-E1.md', $violations[0]->file);
        self::assertSame(151, $violations[0]->line);
        self::assertStringContainsString('151 lines', $violations[0]->message);
    }

    public function testPlanSectionOver150LinesFails(): void
    {
        $violations = $this->check('vorhaben-red-plan', 'section-long');

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_PLAN_SECTION, $violations[0]->rule);
        self::assertSame('docs/vorhaben/section-long/PLAN.md', $violations[0]->file);
        self::assertSame(153, $violations[0]->line);
        self::assertStringContainsString("'E1 Stage number 1' has 151 lines", $violations[0]->message);
    }

    public function testPlanOver16384BytesFails(): void
    {
        $stagePlan = $this->check('vorhaben-red-plan', 'stage-plan-bytes');
        $plan      = $this->check('vorhaben-red-plan', 'plan-bytes');

        foreach ([$stagePlan, $plan] as $violations) {
            self::assertCount(1, $violations);
            self::assertSame(PoolViolation::RULE_PLAN_BYTES, $violations[0]->rule);
            self::assertStringContainsString('16385', $violations[0]->message);
        }
    }

    public function testWithinLimitsPasses(): void
    {
        // green: a stage plan of exactly 150 lines, a PLAN.md section of exactly 150 lines (plus one blank
        // separator line that does not count)
        $files = PoolFixture::loadVorhaben($this->project, 'vorhaben-green', 'demo-one');

        $stagePlans = array_values(array_filter(
            $files,
            static fn ($f): bool => str_ends_with($f->file, 'PLAN-E1.md'),
        ));

        self::assertCount(150, $stagePlans[0]->lines);
        self::assertSame([], (new CheckPlanBudget())($files));
    }

    public function testProgressFileIsNotMeasuredByPlanBudget(): void
    {
        self::assertSame([], $this->check('vorhaben-red-head', 'long'));
    }
}
