<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckPageStructure;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckPageStructureTest extends TestCase
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

    public function testGreenPagePasses(): void
    {
        $pool = PoolFixture::load($this->project, 'green');

        self::assertSame([], (new CheckPageStructure())($pool['pages']));
    }

    public function testMissingSectionFailsWithFileAndLine(): void
    {
        $violations = $this->violationsOf('missing-section');

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_SECTION_MISSING, $violations[0]->rule);
        self::assertSame('.claude/wissen/missing-section.md', $violations[0]->file);
        self::assertSame(
            PoolFixture::lineOf($this->project, '.claude/wissen/missing-section.md', '## Ersetzt'),
            $violations[0]->line,
        );
        self::assertStringContainsString('Fallen', $violations[0]->message);
    }

    public function testForeignSectionFails(): void
    {
        $violations = $this->violationsOf('foreign-section');

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_SECTION_FOREIGN, $violations[0]->rule);
        self::assertSame(
            PoolFixture::lineOf($this->project, '.claude/wissen/foreign-section.md', '## Sonstiges'),
            $violations[0]->line,
        );
    }

    public function testWrongOrderFails(): void
    {
        $violations = $this->violationsOf('wrong-order');

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_SECTION_ORDER, $violations[0]->rule);
        self::assertSame(
            PoolFixture::lineOf($this->project, '.claude/wissen/wrong-order.md', '## Entscheide'),
            $violations[0]->line,
        );
    }

    public function testRepeatedSectionFails(): void
    {
        $this->project->writeFile(
            '.claude/wissen/twice.md',
            "## Stand\n\n- a\n\n## Entscheide\n\n- b\n\n## Fallen\n\n- c\n\n## Ersetzt\n\n- d\n\n## Verweise\n\n- e\n\n## Stand\n\n- f\n",
        );
        $pool = (new \JardisTools\DevSkills\Handler\Pool\LoadPool())($this->project->root);

        $violations = (new CheckPageStructure())($pool['pages']);

        self::assertCount(1, $violations);
        self::assertSame(PoolViolation::RULE_SECTION_ORDER, $violations[0]->rule);
        self::assertSame(21, $violations[0]->line);
    }

    /**
     * @return list<PoolViolation> the violations of one page of the red-structure fixture
     */
    private function violationsOf(string $id): array
    {
        $pool  = PoolFixture::load($this->project, 'red-structure');
        $pages = array_values(array_filter($pool['pages'], static fn (PoolPage $p): bool => $p->id === $id));
        self::assertCount(1, $pages);

        return (new CheckPageStructure())($pages);
    }
}
