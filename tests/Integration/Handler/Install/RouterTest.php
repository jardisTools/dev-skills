<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Handler\Install\BuildManagedBlock;
use JardisTools\DevSkills\Handler\Install\LoadRouterText;
use PHPUnit\Framework\TestCase;

/**
 * Checks the router text that ships in the plugin (router/AGENTS-router.md)
 * against the bundle: every skill it names exists, no retired name appears,
 * the pool pointer stands exactly once, and the text stays small and early
 * in the managed AGENTS.md block.
 */
final class RouterTest extends TestCase
{
    private const POOL_SENTENCE = 'Wissenspool: `.claude/wissen/INDEX.md` — vor Entscheiden lesen, Vermerk-Pflicht';
    private const MAX_BYTES = 4096;
    /**
     * Keywords of the three tier sentences (E7 P7.3, smoke run 1): tier-3 precedence,
     * load duty for `process-choose-tier`, delegation at tier 2. They must stand in the
     * paragraph before "## Tiers", which every session reads.
     */
    public const TIER_RULE_KEYWORDS = [
        'tier 3' => 'Tier 3 is not a judgement call',
        'process-concept' => '`process-concept`',
        'process-choose-tier' => 'only after loading `process-choose-tier`',
        'sub-agent' => 'delegates every subtask to a sub-agent',
        'never implements' => 'never implements itself',
    ];
    /**
     * Keywords of the three gate sentences (E7 P7.3 fix 3, smoke run 3) that no opt-out removes: the skill of a
     * phase is loaded in full through the skill mechanism, and the record skill is loaded before any write to the
     * knowledge pool. A rule that stands only in a skill the path of the session never loads does not reach the
     * session, so these stand in the paragraph before "## Tiers".
     */
    public const GATE_RULE_KEYWORDS = [
        'load in full' => 'load its skill in full through the skill mechanism',
        'shell reading' => 'reading parts of a skill file through the shell does not count',
        'record before write' => 'Before any write to a page of the knowledge pool, load `knowledge-record-decision`',
    ];
    /**
     * Keywords of the git rules (E7 P7.4): branch, commit and merge are gates of the human, no tool attribution,
     * Gitflow. They stand between the marker lines `<!-- git-rules -->` and `<!-- /git-rules -->`; the opt-out
     * `git-rules=false` in composer.json `extra` removes exactly this area.
     */
    public const GIT_RULE_KEYWORDS = [
        'human gates' => 'Branch, commit and merge are gates of the human',
        'no own git' => 'never creates a branch, commits or merges on its own',
        'no attribution' => 'no commit carries a `Co-Authored-By` line or any other tool attribution',
        'gitflow' => 'work happens on a `feature/*` or `fix/*` branch cut from `develop`, a hotfix on a `hotfix/*` branch cut from `main`',
        'gitflow never' => 'never directly on `develop` or `main`',
        'one gate per halt' => 'A halt names exactly one git gate (branch, commit or merge)',
        'next gate after log' => 'the next one only once the step before stands in `git log`',
        'human starts branch' => 'the human starts the branch (`git-start-branch`), the session never offers to create it itself',
    ];
    private const GIT_RULES_START = '<!-- git-rules -->';
    private const GIT_RULES_END = '<!-- /git-rules -->';
    private const FIRST_32_KIB = 32768;
    private const AREA_PREFIXES = [
        'start-', 'packages-', 'design-', 'generated-code-', 'foundation-', 'git-', 'knowledge-', 'process-', 'code-review-',
    ];

    public function testEverySkillNamedInTheRouterExists(): void
    {
        $named = $this->skillNamesIn($this->router());

        self::assertNotEmpty($named);
        foreach ($named as $name) {
            self::assertFileExists(
                $this->root() . '/skills/' . $name . '/SKILL.md',
                sprintf('The router names the skill "%s", which does not ship.', $name),
            );
        }
    }

    public function testRouterNamesNoRetiredSkillName(): void
    {
        $router = $this->router();

        foreach (RenamedSkills::MAPPING as $retired => $current) {
            self::assertSame(
                0,
                preg_match('/(?<![\w-])' . preg_quote($retired, '/') . '(?![\w-])/', $router),
                sprintf('The router names the retired skill "%s"; use "%s".', $retired, $current),
            );
        }
    }

    public function testRouterHasThePoolSentenceExactlyOnce(): void
    {
        self::assertSame(1, substr_count($this->router(), self::POOL_SENTENCE));
        self::assertSame(1, substr_count($this->router(), 'Wissenspool'));
    }

    public function testRouterStatesTierThreePrecedenceLoadDutyAndDelegationBeforeTheTierTable(): void
    {
        $router = $this->router();
        $cut = strpos($router, '## Tiers');
        self::assertIsInt($cut);
        $preface = substr($router, 0, $cut);

        foreach (self::TIER_RULE_KEYWORDS as $label => $keyword) {
            self::assertStringContainsString($keyword, $preface, sprintf('Router preface lacks the %s rule.', $label));
        }
    }

    public function testRouterStatesTheGateRulesBeforeTheTierTable(): void
    {
        $router = $this->router();
        $cut = strpos($router, '## Tiers');
        self::assertIsInt($cut);
        $preface = substr($router, 0, $cut);

        foreach (self::GATE_RULE_KEYWORDS as $label => $keyword) {
            self::assertStringContainsString($keyword, $preface, sprintf('Router preface lacks the %s rule.', $label));
        }
    }

    public function testRouterStatesTheGitRulesBeforeTheTierTableByDefault(): void
    {
        $router = $this->router();
        $cut = strpos($router, '## Tiers');
        self::assertIsInt($cut);
        $preface = substr($router, 0, $cut);

        foreach (self::GIT_RULE_KEYWORDS as $label => $keyword) {
            self::assertStringContainsString($keyword, $preface, sprintf('Router preface lacks the %s rule.', $label));
        }
        self::assertStringNotContainsString('git-rules', $router, 'the marker lines never reach the session');
    }

    public function testGitRulesStandOnlyBetweenTheirMarkerLines(): void
    {
        $raw = (string) file_get_contents($this->root() . '/' . LoadRouterText::RELATIVE_PATH);

        self::assertSame(1, substr_count($raw, "\n" . self::GIT_RULES_START . "\n"));
        self::assertSame(1, substr_count($raw, "\n" . self::GIT_RULES_END . "\n"));
        $start = (int) strpos($raw, self::GIT_RULES_START);
        $end = (int) strpos($raw, self::GIT_RULES_END);
        self::assertLessThan($end, $start);
        self::assertLessThan((int) strpos($raw, '## Tiers'), $end, 'the area ends before the tier table');

        $area = substr($raw, $start, $end - $start);
        $outside = substr($raw, 0, $start) . substr($raw, $end);
        foreach (self::GIT_RULE_KEYWORDS as $label => $keyword) {
            self::assertStringContainsString($keyword, $area, sprintf('The %s rule is not inside the git-rules area.', $label));
            self::assertStringNotContainsString($keyword, $outside, sprintf('The %s rule also stands outside the area.', $label));
        }
        self::assertStringNotContainsString('Co-Authored-By', $outside);
    }

    public function testRouterWithTheOptOutKeepsEveryOtherRuleAndDropsTheGitRules(): void
    {
        $router = (new LoadRouterText())($this->root(), false);
        $cut = strpos($router, '## Tiers');
        self::assertIsInt($cut);
        $preface = substr($router, 0, $cut);

        foreach ([...self::TIER_RULE_KEYWORDS, ...self::GATE_RULE_KEYWORDS] as $label => $keyword) {
            self::assertStringContainsString($keyword, $preface, sprintf('The opt-out router lacks the %s rule.', $label));
        }
        self::assertSame(1, substr_count($router, self::POOL_SENTENCE));
        foreach (self::GIT_RULE_KEYWORDS as $label => $keyword) {
            self::assertStringNotContainsString($keyword, $router, sprintf('The opt-out router still states the %s rule.', $label));
        }
        self::assertStringNotContainsString('Co-Authored-By', $router);
        self::assertStringNotContainsString('git-rules', $router);
        self::assertStringContainsString('`git-start-branch`, `git-commit-change`, `git-push-and-open-pr`', $router, 'the phase table keeps the git skills');
        self::assertStringNotContainsString("\n\n\n", $router, 'no blank-line gap where the area was');
    }

    public function testRouterIsAtMostFourKib(): void
    {
        $file = $this->root() . '/' . LoadRouterText::RELATIVE_PATH;

        self::assertFileExists($file);
        self::assertLessThanOrEqual(self::MAX_BYTES, (int) filesize($file));
    }

    public function testRouterSitsInTheFirst32KiBOfTheBlock(): void
    {
        $router = (new LoadRouterText())($this->root());
        self::assertNotSame('', $router);

        // A heavy package context after the router must not push the router out of the window.
        $block = (new BuildManagedBlock())(
            [new AgentsDescriptor('jardisadapter/cache', str_repeat("Cache rules.\n", 10000))],
            true,
            $router,
        );

        $start = strpos($block, $router);
        self::assertIsInt($start);
        self::assertLessThanOrEqual(self::FIRST_32_KIB, $start + strlen($router));
        self::assertLessThan(self::FIRST_32_KIB, (int) strpos($block, self::POOL_SENTENCE));
    }

    /**
     * @return list<string> skill names the text names in backticks, without duplicates
     */
    private function skillNamesIn(string $text): array
    {
        preg_match_all('/`([a-z][a-z0-9-]*)(?:\/[^`]*)?`/', $text, $matches);

        $names = [];
        foreach ($matches[1] as $token) {
            foreach (self::AREA_PREFIXES as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    $names[$token] = $token;
                    break;
                }
            }
        }

        return array_values($names);
    }

    private function router(): string
    {
        return (new LoadRouterText())($this->root());
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
