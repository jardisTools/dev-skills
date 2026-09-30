<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Validate;

/**
 * Checks that the process skills carry their behaviour rules and caps.
 *
 * Two tables, both constants:
 *   - RULES: per skill, per rule id, the literal marker `<!-- rule:<id> -->`
 *     plus the mandatory keywords of the rule text (docs/SKILL-FORMAT.md §12).
 *   - CAPS: per skill, the cap figures that must appear in the skill text
 *     (brief size, plan length, verdict items, question points, ...).
 *
 * The check proves that a rule or cap is present in the skill, not that an
 * agent follows it. A number keyword (digits only) must appear as a whole
 * number; any other keyword is a literal, case-sensitive substring.
 *
 * A skill from the tables whose SKILL.md does not exist in the checked skills
 * root is a violation: every marker and cap figure is mandatory.
 */
final class CheckRuleMarkers
{
    /**
     * @var array<string, array<string, list<string>>> skill => rule id => mandatory keywords
     */
    public const RULES = [
        'process-choose-tier' => [
            'chat-end-offer' => ['create a project folder?', 'docs/vorhaben/', 'carry knowledge into the pool?'],
            'tier-escalate' => [
                'only with a named reason',
                'the lower tier',
                'two or more subtasks are never',
            ],
            'decide-yourself-no-tier-drop' => ['lowers no tier', 'waives no gate'],
        ],
        'process-run-stage' => [
            'fresh-session-per-stage' => ['fresh agent session'],
            'failure-path' => ['fix run', 'follow-up run', 'STOPP:'],
            'question-points' => ['at most 2 question points', 'STOPP:'],
        ],
        'process-review-board' => [
            'question-points' => ['at most 2 roles'],
        ],
        'process-concept' => [
            'pool-scaffold' => ['.claude/wissen/', 'is missing'],
            'project-profile' => ['.claude/PROJECT_PROFILE.md', 'is missing'],
        ],
    ];

    /**
     * @var array<string, list<string>> skill => cap figures that must appear in the skill text
     */
    public const CAPS = [
        'process-write-plan' => ['150', '16 KB'],
        'process-run-stage' => ['6 KB', '8', '30 KB'],
        'process-verify' => ['5', '3'],
    ];

    /**
     * @param list<string>|null $only restrict the check to these skill names (null = all skills of the tables)
     * @return array<string, list<string>> skill name => violations; skills without violations are absent
     */
    public function __invoke(string $skillsRoot, ?array $only = null): array
    {
        $skills = array_unique([...array_keys(self::RULES), ...array_keys(self::CAPS)]);
        $result = [];

        foreach ($skills as $skill) {
            if ($only !== null && !in_array($skill, $only, true)) {
                continue;
            }

            $file = $skillsRoot . '/' . $skill . '/SKILL.md';
            if (!is_file($file)) {
                $result[$skill] = ['skill is missing, it must carry its rule markers and caps'];
                continue;
            }

            $errors = $this->checkSkill($skill, (string) file_get_contents($file));
            if ($errors !== []) {
                $result[$skill] = $errors;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function checkSkill(string $skill, string $content): array
    {
        $errors = [];

        foreach (self::RULES[$skill] ?? [] as $ruleId => $keywords) {
            $marker = sprintf('<!-- rule:%s -->', $ruleId);
            if (!str_contains($content, $marker)) {
                $errors[] = sprintf("marker '%s' is missing", $marker);
            }
            foreach ($keywords as $keyword) {
                if (!$this->containsKeyword($content, $keyword)) {
                    $errors[] = sprintf("keyword '%s' of rule '%s' is missing", $keyword, $ruleId);
                }
            }
        }

        foreach (self::CAPS[$skill] ?? [] as $keyword) {
            if (!$this->containsKeyword($content, $keyword)) {
                $errors[] = sprintf("cap figure '%s' is missing", $keyword);
            }
        }

        return $errors;
    }

    private function containsKeyword(string $content, string $keyword): bool
    {
        if (ctype_digit($keyword)) {
            return preg_match('/(?<![0-9])' . $keyword . '(?![0-9])/', $content) === 1;
        }

        return str_contains($content, $keyword);
    }
}
