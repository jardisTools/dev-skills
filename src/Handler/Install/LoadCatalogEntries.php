<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\CatalogEntry;
use JardisTools\DevSkills\Handler\Build\ParseManifest;

/**
 * Reads `<pluginRoot>/catalog/manifest.json`; a missing or unreadable manifest yields no entries, so the
 * short block falls back to the package intro instead of failing the install.
 */
final class LoadCatalogEntries
{
    /** @var Closure(string): list<CatalogEntry> */
    private readonly Closure $parseManifest;

    public function __construct(?Closure $parseManifest = null)
    {
        $this->parseManifest = $parseManifest ?? (new ParseManifest())->__invoke(...);
    }

    /**
     * @return list<CatalogEntry>
     */
    public function __invoke(string $pluginRoot): array
    {
        $path = $pluginRoot . '/catalog/manifest.json';
        if (!is_file($path)) {
            return [];
        }

        try {
            return ($this->parseManifest)($path);
        } catch (\RuntimeException) {
            return [];
        }
    }
}
