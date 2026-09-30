<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\PoolViolation;

/**
 * Checks the section layout of topic pages: exactly the five level-2 sections Stand, Entscheide, Fallen,
 * Ersetzt, Verweise, each once, in this order. A missing section is reported at the line where it belongs
 * (the next section present, or the end of the file), a foreign or misplaced heading at its own line.
 */
final class CheckPageStructure
{
    private const SECTIONS = ['Stand', 'Entscheide', 'Fallen', 'Ersetzt', 'Verweise'];

    /**
     * @param list<PoolPage> $pages topic pages (not INDEX.md)
     * @return list<PoolViolation>
     */
    public function __invoke(array $pages): array
    {
        $violations = [];
        foreach ($pages as $page) {
            array_push($violations, ...$this->checkPage($page));
        }

        return $violations;
    }

    /**
     * @return list<PoolViolation>
     */
    private function checkPage(PoolPage $page): array
    {
        $violations = [];
        $seen       = [];
        $last       = -1;

        foreach ($page->headings as $heading) {
            $position = array_search($heading['title'], self::SECTIONS, true);
            if ($position === false) {
                $violations[] = new PoolViolation(
                    $page->file,
                    $heading['line'],
                    PoolViolation::RULE_SECTION_FOREIGN,
                    sprintf("section '%s' is not one of %s", $heading['title'], implode(', ', self::SECTIONS)),
                );
                continue;
            }
            if (isset($seen[$position]) || $position < $last) {
                $violations[] = new PoolViolation(
                    $page->file,
                    $heading['line'],
                    PoolViolation::RULE_SECTION_ORDER,
                    sprintf(
                        "section '%s' is repeated or out of order, expected %s",
                        $heading['title'],
                        implode(', ', self::SECTIONS),
                    ),
                );
            } else {
                $last = $position;
            }
            $seen[$position] ??= $heading['line'];
        }

        foreach (self::SECTIONS as $position => $title) {
            if (!isset($seen[$position])) {
                $violations[] = new PoolViolation(
                    $page->file,
                    $this->lineOfMissing($seen, $position, $page->lineCount),
                    PoolViolation::RULE_SECTION_MISSING,
                    sprintf("section '%s' is missing", $title),
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<int, int> $seen section position => line of its first heading
     */
    private function lineOfMissing(array $seen, int $position, int $lineCount): int
    {
        $following = array_filter($seen, static fn (int $p): bool => $p > $position, ARRAY_FILTER_USE_KEY);

        return $following === [] ? max(1, $lineCount) : min($following);
    }
}
