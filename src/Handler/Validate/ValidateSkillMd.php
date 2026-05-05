<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Validate;

/**
 * Validates a single SKILL.md file against the authoring standard documented
 * in docs/SKILL-FORMAT.md (v3). Returns a list of human-readable violation
 * messages — empty list means the file is conformant.
 *
 * Invariants checked:
 *   - frontmatter exists, required fields present
 *   - name is kebab-case and matches the directory
 *   - zone is one of the four known zones
 *   - description is a single line, ≤60 words
 *   - prerequisites / next are arrays
 *   - body contains at least one section heading (## or ###)
 *   - file length stays within the zone-specific budget
 *
 * Body structure (heading names, section count) is NOT prescribed — skills
 * choose the shape that fits their content. See docs/SKILL-FORMAT.md §4.
 *
 * Design: a single closure with one __invoke is intentional. The validator
 * is a linear pipeline of independent checks; splitting each check into its
 * own closure would add boilerplate without architectural benefit.
 */
final class ValidateSkillMd
{
    /** @var array<string, int> */
    private const ZONE_LINE_BUDGET = [
        'crosscut'       => 150,
        'pre'            => 250,
        'post-reference' => 250,
        'post-active'    => 550,
    ];

    /** @var list<string> */
    private const REQUIRED_FRONTMATTER_FIELDS = [
        'name',
        'description',
        'zone',
        'prerequisites',
        'next',
    ];

    private const MAX_DESCRIPTION_WORDS = 60;

    /**
     * @return list<string> List of violations. Empty = file is conformant.
     */
    public function __invoke(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [sprintf('file does not exist: %s', $filePath)];
        }

        $content = (string) file_get_contents($filePath);
        $errors  = [];

        if (!preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $content, $match)) {
            return ['frontmatter not found (file must start with --- and contain a closing ---)'];
        }

        $frontmatterRaw = $match[1];
        $body           = $match[2];
        $fields         = $this->parseFrontmatter($frontmatterRaw);

        foreach (self::REQUIRED_FRONTMATTER_FIELDS as $required) {
            if (!array_key_exists($required, $fields)) {
                $errors[] = sprintf("frontmatter: missing required field '%s'", $required);
            }
        }

        if (isset($fields['name']) && is_string($fields['name'])) {
            $name = $fields['name'];
            if (preg_match('/^[a-z]+(-[a-z0-9]+)*$/', $name) !== 1) {
                $errors[] = sprintf("frontmatter: name '%s' must be kebab-case (lowercase letters, dashes)", $name);
            }
            $expectedName = basename(dirname($filePath));
            if ($name !== $expectedName) {
                $errors[] = sprintf(
                    "frontmatter: name '%s' must match directory name '%s'",
                    $name,
                    $expectedName,
                );
            }
        }

        if (isset($fields['zone']) && is_string($fields['zone'])) {
            if (!array_key_exists($fields['zone'], self::ZONE_LINE_BUDGET)) {
                $errors[] = sprintf(
                    "frontmatter: invalid zone '%s', must be one of: %s",
                    $fields['zone'],
                    implode(', ', array_keys(self::ZONE_LINE_BUDGET)),
                );
            }
        }

        if (isset($fields['description'])) {
            $errors = array_merge($errors, $this->validateDescription($fields['description']));
        }

        foreach (['prerequisites', 'next'] as $arrayField) {
            if (array_key_exists($arrayField, $fields) && !is_array($fields[$arrayField])) {
                $errors[] = sprintf(
                    "frontmatter: '%s' must be an array (use [] for empty)",
                    $arrayField,
                );
            }
        }

        if (preg_match('/^#{2,3} \S/m', $body) !== 1) {
            $errors[] = "body: must contain at least one '##' or '###' section heading";
        }

        if (
            isset($fields['zone'])
            && is_string($fields['zone'])
            && array_key_exists($fields['zone'], self::ZONE_LINE_BUDGET)
        ) {
            $budget = self::ZONE_LINE_BUDGET[$fields['zone']];
            $lines  = substr_count($content, "\n") + 1;
            if ($lines > $budget) {
                $errors[] = sprintf(
                    "length: %d lines exceeds budget of %d for zone '%s'",
                    $lines,
                    $budget,
                    $fields['zone'],
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateDescription(mixed $value): array
    {
        if (!is_string($value)) {
            return ['frontmatter: description must be a string'];
        }
        $errors = [];
        if (str_contains($value, "\n")) {
            $errors[] = 'frontmatter: description must be a single line';
        }
        $words = str_word_count(strip_tags($value));
        if ($words > self::MAX_DESCRIPTION_WORDS) {
            $errors[] = sprintf(
                'frontmatter: description has %d words, max %d',
                $words,
                self::MAX_DESCRIPTION_WORDS,
            );
        }
        return $errors;
    }

    /**
     * Minimal YAML-subset parser for SKILL.md frontmatter. Supports:
     *   key: value             → string
     *   key: [a, b, c]         → list<string>
     *   key: []                → list (empty)
     *
     * Multiline strings, nested objects, anchors etc. are not supported —
     * they are not allowed by SKILL-FORMAT.md anyway.
     *
     * @return array<string, string|list<string>>
     */
    private function parseFrontmatter(string $raw): array
    {
        $fields = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^([a-z][a-z0-9_]*)\s*:\s*(.*)$/i', $line, $m) !== 1) {
                continue;
            }
            $key   = $m[1];
            $value = trim($m[2]);

            if ($value === '[]') {
                $fields[$key] = [];
                continue;
            }
            if (preg_match('/^\[(.*)\]$/', $value, $am) === 1) {
                $items = array_values(array_filter(
                    array_map(static fn (string $s): string => trim($s, " \t\"'"), explode(',', $am[1])),
                    static fn (string $s): bool => $s !== '',
                ));
                $fields[$key] = $items;
                continue;
            }

            $fields[$key] = trim($value, " \t\"'");
        }
        return $fields;
    }
}
