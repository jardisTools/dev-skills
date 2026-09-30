<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Handler\Pool\CheckProgressHead;
use JardisTools\DevSkills\Tests\Support\PoolFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckProgressHeadTest extends TestCase
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
        return (new CheckProgressHead())(PoolFixture::loadVorhaben($this->project, $case, $folder));
    }

    /**
     * @param list<PoolViolation> $violations
     * @return list<string>
     */
    private function rules(array $violations): array
    {
        return array_map(static fn (PoolViolation $v): string => $v->rule, $violations);
    }

    public function testGreenHeadPasses(): void
    {
        self::assertSame([], $this->check('vorhaben-green'));
    }

    public function testHeadNotFirstHeadingFails(): void
    {
        $position = $this->check('vorhaben-red-head', 'position');
        $missing  = $this->check('vorhaben-red-head', 'missing');

        self::assertSame([PoolViolation::RULE_HEAD_POSITION], $this->rules($position));
        self::assertSame('docs/vorhaben/position/PROGRESS.md', $position[0]->file);
        self::assertSame(3, $position[0]->line);
        self::assertSame([PoolViolation::RULE_HEAD_MISSING], $this->rules($missing));
    }

    public function testWrongKeyOrderFails(): void
    {
        $violations = $this->check('vorhaben-red-head', 'key-order');

        self::assertSame([PoolViolation::RULE_HEAD_KEY, PoolViolation::RULE_HEAD_KEY], $this->rules($violations));
        self::assertSame(4, $violations[0]->line);
        self::assertStringContainsString("expected key 'Phase'", $violations[0]->message);
    }

    public function testUnknownPhaseFails(): void
    {
        $violations = $this->check('vorhaben-red-head', 'phase');

        self::assertSame([PoolViolation::RULE_HEAD_PHASE], $this->rules($violations));
        self::assertSame(4, $violations[0]->line);
        self::assertStringContainsString("'planning'", $violations[0]->message);
    }

    public function testStageIsDashBeforeStageAndNumberedFromStage(): void
    {
        $before = $this->check('vorhaben-red-head', 'stage-before');
        $from   = $this->check('vorhaben-red-head', 'stage-from');

        self::assertSame([PoolViolation::RULE_HEAD_STAGE], $this->rules($before));
        self::assertSame([PoolViolation::RULE_HEAD_STAGE], $this->rules($from));
        self::assertSame(5, $before[0]->line);
        // green: dash before stage (plan), numbered from stage on (stage, close)
        self::assertSame([], $this->check('vorhaben-green', 'demo-two'));
        self::assertSame([], $this->check('vorhaben-green', 'demo-one'));
        self::assertSame([], $this->check('vorhaben-green', 'demo-three'));
    }

    public function testOpenDecisionsDashOrStoppForm(): void
    {
        $badDate = $this->check('vorhaben-red-head', 'decisions');
        $badForm = $this->check('vorhaben-red-head', 'decisions-form');

        self::assertSame([PoolViolation::RULE_HEAD_DECISIONS], $this->rules($badDate));
        self::assertSame([PoolViolation::RULE_HEAD_DECISIONS], $this->rules($badForm));
        self::assertSame(7, $badDate[0]->line);
        // green: the dash (demo-one) and a well-formed STOPP line (demo-two)
        self::assertSame([], $this->check('vorhaben-green', 'demo-two'));
    }

    public function testFileOver60LinesFails(): void
    {
        $violations = $this->check('vorhaben-red-head', 'long');

        self::assertSame([PoolViolation::RULE_PROGRESS_LINES], $this->rules($violations));
        self::assertSame(61, $violations[0]->line);
        self::assertStringContainsString('61 lines', $violations[0]->message);
    }

    public function testFileWithExactly60LinesPasses(): void
    {
        $head = "# Progress demo\n\n## Kopf\n- **Phase:** plan\n- **Stage:** —\n- **Next step:** Cut stage E1\n"
            . "- **Open decisions:** —\n";
        $this->project->writeFile(
            'docs/vorhaben/edge/PROGRESS.md',
            $head . str_repeat("- Free line.\n", 60 - 7),
        );
        $this->project->writeFile('.claude/wissen/INDEX.md', "# Index\n");

        $files = (new \JardisTools\DevSkills\Handler\Pool\LoadVorhaben())($this->project->root);

        self::assertCount(60, $files[0]->lines);
        self::assertSame([], (new CheckProgressHead())($files));
    }

    public function testHeadInsideCodeFenceIsNotTheHead(): void
    {
        $this->project->writeFile(
            'docs/vorhaben/fenced/PROGRESS.md',
            "# Progress demo\n\n```markdown\n## Kopf\n- **Phase:** plan\n```\n",
        );

        $files = (new \JardisTools\DevSkills\Handler\Pool\LoadVorhaben())($this->project->root);

        self::assertSame([PoolViolation::RULE_HEAD_MISSING], $this->rules((new CheckProgressHead())($files)));
    }
}
