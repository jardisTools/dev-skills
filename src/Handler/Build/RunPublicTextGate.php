<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

use JardisTools\DevSkills\Data\PublicTextGateResult;
use JardisTools\DevSkills\Data\PublicTextScope;

/**
 * Decides how the public-text gate runs: a denylist (one term per line) that
 * is empty while required is an error and nothing is scanned; empty and not
 * required means home-path regex only; otherwise the terms are applied too.
 */
final class RunPublicTextGate
{
    public function __construct(private readonly CheckPublicText $check = new CheckPublicText())
    {
    }

    public function __invoke(
        string $repoRoot,
        PublicTextScope $scope,
        string $denylist,
        bool $required,
    ): PublicTextGateResult {
        $terms = array_values(array_filter(
            array_map('trim', explode("\n", $denylist)),
            static fn (string $term): bool => $term !== '',
        ));

        if ($terms === [] && $required) {
            return new PublicTextGateResult(
                'PUBLIC_TEXT_DENYLIST is empty but required (PUBLIC_TEXT_REQUIRE_DENYLIST=1).',
                true,
                0,
                [],
            );
        }

        return new PublicTextGateResult(
            null,
            $terms === [],
            count($scope->paths) + count($scope->regexOnlyPaths),
            ($this->check)($repoRoot, $scope, $terms),
        );
    }
}
