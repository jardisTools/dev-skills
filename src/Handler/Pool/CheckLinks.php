<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolPage;
use JardisTools\DevSkills\Data\PoolViolation;

/**
 * Checks the links of pool files. A `[[target]]` must be a page id (whether the page exists is decided by
 * CheckSupersession, which knows the redirects). A relative `[text](target)` must resolve, relative to the
 * folder of the page, to an existing file or folder inside the project; external URLs, absolute paths and
 * pure anchors are not checked, and no link is followed.
 */
final class CheckLinks
{
    public function __construct(private readonly ResolvePath $resolvePath = new ResolvePath())
    {
    }

    /**
     * @param list<PoolPage> $files every pool file, INDEX.md included
     * @return list<PoolViolation>
     */
    public function __invoke(string $root, array $files): array
    {
        $violations = [];
        foreach ($files as $page) {
            foreach ($page->wikiLinks as $link) {
                if (preg_match(PoolPage::ID_PATTERN, $link['target']) !== 1) {
                    $violations[] = new PoolViolation(
                        $page->file,
                        $link['line'],
                        PoolViolation::RULE_LINK_DEAD,
                        sprintf("link '[[%s]]' does not name a page id", $link['target']),
                    );
                }
            }
            foreach ($page->mdLinks as $link) {
                $target = $this->localTarget($link['target']);
                if ($target !== null && ($this->resolvePath)($root, dirname($page->file) . '/' . $target) === null) {
                    $violations[] = new PoolViolation(
                        $page->file,
                        $link['line'],
                        PoolViolation::RULE_LINK_DEAD,
                        sprintf("link target '%s' does not exist inside the project", $link['target']),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * The path part of a link that points at the project, or null for a link that is not checked.
     */
    private function localTarget(string $raw): ?string
    {
        $target = (string) preg_replace('/[#?].*$/', '', trim($raw, '<>'));
        if ($target === '' || preg_match('#^([a-z][a-z0-9+.\-]*:|/)#i', $target) === 1) {
            return null;
        }

        return rawurldecode($target);
    }
}
