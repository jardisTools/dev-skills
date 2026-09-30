<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Outcome of reading the manifest: the state, the manifest when one could be
 * read (Healthy, or TooNew with versions only) and a warning for unhealthy states.
 */
final class ManifestReadResult
{
    public function __construct(
        public readonly ManifestState $state,
        public readonly ?Manifest $manifest = null,
        public readonly string $warning = '',
    ) {
    }
}
