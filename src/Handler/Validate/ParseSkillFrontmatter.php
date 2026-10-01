<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Validate;

/**
 * Splits a SKILL.md into its frontmatter fields and body. Returns null when the
 * file has no frontmatter block (must start with `---` and contain a closing `---`).
 *
 * Minimal YAML-subset parser. Supports:
 *   key: value             → string
 *   key: [a, b, c]         → list<string>
 *   key: []                → list (empty)
 *
 * Multiline strings, nested objects, anchors etc. are not supported —
 * they are not allowed by SKILL-FORMAT.md anyway.
 */
final class ParseSkillFrontmatter
{
    /**
     * @return array{fields: array<string, string|list<string>>, body: string}|null
     */
    public function __invoke(string $content): ?array
    {
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $content, $match) !== 1) {
            return null;
        }

        $fields = [];
        foreach (explode("\n", $match[1]) as $line) {
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
                $fields[$key] = array_values(array_filter(
                    array_map(static fn (string $s): string => trim($s, " \t\"'"), explode(',', $am[1])),
                    static fn (string $s): bool => $s !== '',
                ));
                continue;
            }

            $fields[$key] = trim($value, " \t\"'");
        }

        return ['fields' => $fields, 'body' => $match[2]];
    }
}
