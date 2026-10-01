<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Handler\Validate\CheckRuleMarkers;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckRuleMarkersTest extends TestCase
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

    public function testTableCoversTheMarkersOfTheProcessRules(): void
    {
        $markers = [];
        foreach (CheckRuleMarkers::RULES as $skill => $rules) {
            foreach (array_keys($rules) as $ruleId) {
                $markers[] = $skill . ':' . $ruleId;
            }
        }
        sort($markers);

        self::assertSame([
            'process-choose-tier:chat-end-offer',
            'process-choose-tier:decide-yourself-no-tier-drop',
            'process-choose-tier:tier-escalate',
            'process-close:close-lessons-via-skill',
            'process-concept:decide-yourself-no-gate-waiver',
            'process-concept:pool-scaffold',
            'process-concept:project-profile',
            'process-review-board:question-points',
            'process-run-stage:commit-is-human-gate',
            'process-run-stage:failure-path',
            'process-run-stage:fresh-session-per-stage',
            'process-run-stage:question-points',
            'process-write-prd:decide-yourself-no-gate-waiver',
        ], $markers); // eleven entries: eight rules plus the three of E7 P7.3 fix 2; question-points lives in two skills, decide-yourself-no-gate-waiver in two
    }

    public function testKeywordsAreTheEnglishPhrasesOfTheFormatDoc(): void
    {
        // Binding source: docs/SKILL-FORMAT.md section 12; change both together.
        self::assertSame([
            'process-choose-tier' => [
                'chat-end-offer' => ['create a project folder?', 'docs/vorhaben/', 'carry knowledge into the pool?'],
                'tier-escalate' => ['only with a named reason', 'the lower tier', 'two or more subtasks are never'],
                'decide-yourself-no-tier-drop' => ['lowers no tier', 'waives no gate'],
            ],
            'process-run-stage' => [
                'fresh-session-per-stage' => ['fresh agent session'],
                'failure-path' => ['fix run', 'follow-up run', 'STOPP:'],
                'question-points' => ['at most 2 question points', 'STOPP:'],
                'commit-is-human-gate' => ['gate of the human', 'git log'],
            ],
            'process-review-board' => [
                'question-points' => ['at most 2 roles'],
            ],
            'process-concept' => [
                'pool-scaffold' => ['.claude/wissen/', 'is missing'],
                'project-profile' => ['.claude/PROJECT_PROFILE.md', 'is missing'],
                'decide-yourself-no-gate-waiver' => ['waives no gate', 'open-question gate'],
            ],
            'process-write-prd' => [
                'decide-yourself-no-gate-waiver' => ['waives no gate', 'open-question gate'],
            ],
            'process-close' => [
                'close-lessons-via-skill' => ['before the first write', 'never from memory'],
            ],
        ], CheckRuleMarkers::RULES);
    }

    public function testGreenFixtureWithAllSkillsPasses(): void
    {
        $this->writeAllProcessSkills();

        self::assertSame([], (new CheckRuleMarkers())($this->project->path('skills')));
    }

    public function testMissingMarkerIsReported(): void
    {
        $this->writeAllProcessSkills();
        $text = $this->fullText('process-choose-tier');
        $this->writeSkillText('process-choose-tier', str_replace('<!-- rule:tier-escalate -->', '', $text));

        $result = (new CheckRuleMarkers())($this->project->path('skills'));

        self::assertSame(
            ['process-choose-tier' => ["marker '<!-- rule:tier-escalate -->' is missing"]],
            $result,
        );
    }

    public function testMissingKeywordIsReported(): void
    {
        $this->writeAllProcessSkills();
        $text = $this->fullText('process-choose-tier');
        $this->writeSkillText('process-choose-tier', str_replace('carry knowledge into the pool?', 'x', $text));

        $result = (new CheckRuleMarkers())($this->project->path('skills'));

        self::assertSame(
            ['process-choose-tier' => ["keyword 'carry knowledge into the pool?' of rule 'chat-end-offer' is missing"]],
            $result,
        );
    }

    public function testMissingCapFigureIsReported(): void
    {
        $this->writeAllProcessSkills();
        $text = $this->fullText('process-write-plan');
        $this->writeSkillText('process-write-plan', str_replace('16 KB', 'sixteen', $text));

        $result = (new CheckRuleMarkers())($this->project->path('skills'));

        self::assertSame(['process-write-plan' => ["cap figure '16 KB' is missing"]], $result);
    }

    public function testNumberKeywordMatchesWholeNumbersOnly(): void
    {
        $this->writeAllProcessSkills();
        // "5" only occurs inside "15" / "50": must not count as the cap figure
        $text = preg_replace('/(?<![0-9])5(?![0-9])/', '15', $this->fullText('process-verify'));
        $this->writeSkillText('process-verify', (string) $text . "\n50\n");

        $result = (new CheckRuleMarkers())($this->project->path('skills'));

        self::assertSame(['process-verify' => ["cap figure '5' is missing"]], $result);
    }

    public function testEveryCapFigureOfEverySkillIsEnforced(): void
    {
        foreach (CheckRuleMarkers::CAPS as $skill => $figures) {
            foreach ($figures as $figure) {
                $project = new TempProject();
                try {
                    $this->writeAllProcessSkills($project);
                    $text = $this->fullText($skill);
                    // drop every occurrence of the figure; other figures stay
                    $text = (string) preg_replace('/(?<![0-9])' . preg_quote($figure, '/') . '(?![0-9])/', 'n', $text);
                    $project->writeFile('skills/' . $skill . '/SKILL.md', $text);

                    $result = (new CheckRuleMarkers())($project->path('skills'));

                    self::assertArrayHasKey($skill, $result, $skill . ' / ' . $figure);
                    self::assertContains(
                        sprintf("cap figure '%s' is missing", $figure),
                        $result[$skill],
                        $skill . ' / ' . $figure,
                    );
                } finally {
                    $project->cleanup();
                }
            }
        }
    }

    public function testTheShippedBundleCarriesEveryMarkerAndCapFigure(): void
    {
        self::assertSame([], (new CheckRuleMarkers())(dirname(__DIR__, 4) . '/skills'));
    }

    public function testMissingSkillIsAViolation(): void
    {
        $this->project->mkdir('skills');

        $result = (new CheckRuleMarkers())($this->project->path('skills'));

        self::assertEqualsCanonicalizing(
            ['process-choose-tier', 'process-concept', 'process-run-stage', 'process-review-board', 'process-write-plan', 'process-verify', 'process-write-prd', 'process-close'],
            array_keys($result),
        );
    }

    public function testOnlyRestrictsTheCheckedSkills(): void
    {
        $this->writeAllProcessSkills();
        $this->writeSkillText('process-choose-tier', "nothing\n");

        self::assertSame([], (new CheckRuleMarkers())($this->project->path('skills'), ['process-verify']));
        self::assertArrayHasKey(
            'process-choose-tier',
            (new CheckRuleMarkers())($this->project->path('skills'), ['process-choose-tier']),
        );
    }

    public function testSkillOutsideTheTablesIsNeverChecked(): void
    {
        $this->writeSkillText('git-commit-change', "no markers here\n");

        self::assertSame([], (new CheckRuleMarkers())($this->project->path('skills'), ['git-commit-change']));
    }

    private function writeAllProcessSkills(?TempProject $project = null): void
    {
        $project ??= $this->project;
        $skills = array_unique([...array_keys(CheckRuleMarkers::RULES), ...array_keys(CheckRuleMarkers::CAPS)]);
        foreach ($skills as $skill) {
            $project->writeFile('skills/' . $skill . '/SKILL.md', $this->fullText($skill));
        }
    }

    private function writeSkillText(string $skill, string $text): void
    {
        $this->project->writeFile('skills/' . $skill . '/SKILL.md', $text);
    }

    /**
     * A skill text carrying every marker, keyword and cap figure of the tables
     * for $skill, each on its own line.
     */
    private function fullText(string $skill): string
    {
        $lines = ['---', 'name: ' . $skill, '---', ''];
        foreach (CheckRuleMarkers::RULES[$skill] ?? [] as $ruleId => $keywords) {
            $lines[] = sprintf('<!-- rule:%s -->', $ruleId);
            array_push($lines, ...$keywords);
        }
        array_push($lines, ...(CheckRuleMarkers::CAPS[$skill] ?? []));

        return implode("\n", $lines) . "\n";
    }
}
