<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * The stance of the git rules in the router text: `strict` (the default, `git-rules: true`) keeps
 * branch, commit and merge as gates of the human, `delegated` (`git-rules: "delegated"`) lets the
 * session create the branch and the commits itself while merge and push stay gates of the human,
 * `off` (`git-rules: false`) leaves the git rules out of the router.
 */
enum GitRulesMode: string
{
    case Strict = 'strict';
    case Delegated = 'delegated';
    case Off = 'off';
}
