<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

/**
 * Checks that the topmost version heading (`## [<version>]`) of a changelog is the version about to be
 * tagged. The date behind the version is not checked. `[Unreleased]` on top is an error, as is any other
 * version or a file without a version heading. Returns null when the top is right, otherwise the error
 * text with the expected and the found heading.
 */
final class CheckChangelogTop
{
    private const HEADING_REGEX = '/^## \[([^\]\r\n]*)\]/m';

    public function __invoke(string $changelog, string $version): ?string
    {
        if (preg_match(self::HEADING_REGEX, $changelog, $match) !== 1) {
            return sprintf('Changelog top: expected [%s], found no version heading.', $version);
        }

        if ($match[1] === $version) {
            return null;
        }

        return sprintf('Changelog top: expected [%s], found [%s].', $version, $match[1]);
    }
}
