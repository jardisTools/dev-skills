<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * One finding of the public-text check. Carries location and kind only,
 * never the matched text itself.
 */
final class PublicTextViolation
{
    public const KIND_HOME_PATH = 'home-path';
    public const KIND_DENYLIST  = 'denylist';

    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $kind,
    ) {
    }
}
