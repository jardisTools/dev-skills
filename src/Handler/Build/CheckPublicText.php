<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

use JardisTools\DevSkills\Data\PublicTextScope;
use JardisTools\DevSkills\Data\PublicTextViolation;

/**
 * Scans the given files for local home paths (always) and for denylist terms
 * (only when terms are passed). Returns location and kind of each finding,
 * never the matched text. Missing or binary files are skipped.
 */
final class CheckPublicText
{
    private const HOME_PATH_REGEX = '#/(Users|home)/[^/\s]+/#';

    /**
     * @param list<string> $terms
     * @return list<PublicTextViolation>
     */
    public function __invoke(string $repoRoot, PublicTextScope $scope, array $terms = []): array
    {
        $terms = array_values(array_filter(
            array_map('trim', $terms),
            static fn (string $term): bool => $term !== '',
        ));

        $violations = [];
        foreach ($scope->paths as $path) {
            array_push($violations, ...$this->scan($repoRoot, $path, $terms));
        }
        foreach ($scope->regexOnlyPaths as $path) {
            array_push($violations, ...$this->scan($repoRoot, $path, []));
        }

        return $violations;
    }

    /**
     * @param list<string> $terms
     * @return list<PublicTextViolation>
     */
    private function scan(string $repoRoot, string $path, array $terms): array
    {
        $full = $repoRoot . '/' . $path;
        if (!is_file($full)) {
            return [];
        }

        $content = file_get_contents($full);
        if ($content === false || str_contains($content, "\0")) {
            return [];
        }

        $found = [];
        foreach (explode("\n", $content) as $index => $line) {
            $number = $index + 1;

            if (preg_match(self::HOME_PATH_REGEX, $line) === 1) {
                $found[] = new PublicTextViolation($path, $number, PublicTextViolation::KIND_HOME_PATH);
            }

            foreach ($terms as $term) {
                if (stripos($line, $term) !== false) {
                    $found[] = new PublicTextViolation($path, $number, PublicTextViolation::KIND_DENYLIST);
                    break;
                }
            }
        }

        return $found;
    }
}
