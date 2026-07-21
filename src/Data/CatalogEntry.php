<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Immutable value object representing one entry in the Jardis package catalog.
 * Self-validates in the constructor so that every instance is guaranteed to
 * carry non-empty values for all required fields.
 */
final readonly class CatalogEntry
{
    public function __construct(
        public string $package,
        public string $capability,
        public string $useWhen,
        public string $composerRequire,
        public ?string $alternatives = null,
    ) {
        if ($package === '') {
            throw new \InvalidArgumentException('CatalogEntry: package must not be empty.');
        }
        if ($capability === '') {
            throw new \InvalidArgumentException('CatalogEntry: capability must not be empty.');
        }
        if ($useWhen === '') {
            throw new \InvalidArgumentException('CatalogEntry: use_when must not be empty.');
        }
        if ($composerRequire === '') {
            throw new \InvalidArgumentException('CatalogEntry: composer_require must not be empty.');
        }
    }
}
