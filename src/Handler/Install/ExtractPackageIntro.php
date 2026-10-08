<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

/**
 * Returns the introduction of a package AGENTS.md: the text between the H1 heading and the first `##` heading.
 */
final class ExtractPackageIntro
{
    public function __invoke(string $content): string
    {
        $intro = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (str_starts_with($line, '## ')) {
                break;
            }
            if (str_starts_with($line, '# ')) {
                $intro = [];
                continue;
            }
            $intro[] = $line;
        }

        return trim(implode("\n", $intro));
    }
}
