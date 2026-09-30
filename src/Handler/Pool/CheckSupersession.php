<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\PoolViolation;

/**
 * Checks the target of every `[[page]]` edge. A page without a file is fine when some page lists its id in
 * the frontmatter list `ersetzt` (the link redirects to that page); otherwise the edge is dead.
 */
final class CheckSupersession
{
    /**
     * @param list<PoolPage> $files every pool file, INDEX.md included
     * @return list<PoolViolation>
     */
    public function __invoke(array $files): array
    {
        $known = [];
        foreach ($files as $page) {
            $known[$page->id] = true;
            foreach ($page->ersetzt as $replaced) {
                $known[$replaced] = true;
            }
        }

        $violations = [];
        foreach ($files as $page) {
            foreach ($page->wikiLinks as $link) {
                if (preg_match(PoolPage::ID_PATTERN, $link['target']) === 1 && !isset($known[$link['target']])) {
                    $violations[] = new PoolViolation(
                        $page->file,
                        $link['line'],
                        PoolViolation::RULE_EDGE_DEAD,
                        sprintf("page '%s' has no file and no page lists it in ersetzt", $link['target']),
                    );
                }
            }
        }

        return $violations;
    }
}
