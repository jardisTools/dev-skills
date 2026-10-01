<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Validate;

use JardisTools\DevSkills\Data\RenamedSkills;

/**
 * Checks that every entry of a SKILL.md's `prerequisites` and `next` points at
 * a skill folder (containing a SKILL.md) next to the checked skill, i.e. inside
 * the same skills root. A retired name (a key of RenamedSkills::MAPPING) and an
 * unknown name are both violations.
 *
 * Returns human-readable violations; an empty list means all links resolve.
 */
final class CheckSkillLinks
{
    private const LINK_FIELDS = ['prerequisites', 'next'];

    public function __construct(private readonly ParseSkillFrontmatter $parseFrontmatter = new ParseSkillFrontmatter())
    {
    }

    /**
     * @return list<string>
     */
    public function __invoke(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [sprintf('file does not exist: %s', $filePath)];
        }

        $document = ($this->parseFrontmatter)((string) file_get_contents($filePath));
        if ($document === null) {
            return ['frontmatter not found, links cannot be checked'];
        }

        $skillsRoot = dirname($filePath, 2);
        $errors     = [];

        foreach (self::LINK_FIELDS as $field) {
            $links = $document['fields'][$field] ?? [];
            if (!is_array($links)) {
                continue; // a non-array value is reported by ValidateSkillMd
            }
            foreach ($links as $link) {
                $error = $this->checkLink($skillsRoot, $field, $link);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    private function checkLink(string $skillsRoot, string $field, string $link): ?string
    {
        if (array_key_exists($link, RenamedSkills::MAPPING)) {
            return sprintf(
                "%s entry '%s' is a retired skill name, use '%s'",
                $field,
                $link,
                RenamedSkills::MAPPING[$link],
            );
        }

        if (
            preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $link) !== 1
            || !is_file($skillsRoot . '/' . $link . '/SKILL.md')
        ) {
            return sprintf("%s entry '%s' does not match any skill folder", $field, $link);
        }

        return null;
    }
}
