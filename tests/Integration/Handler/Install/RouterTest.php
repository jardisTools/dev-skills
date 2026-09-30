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
