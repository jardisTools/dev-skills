<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

/**
 * Port: fetches the list of package names for a given Packagist vendor.
 *
 * Implementations MUST return null on any failure — network errors,
 * HTTP errors, malformed JSON, or empty results must never propagate as
 * exceptions. Callers treat null / [] as "skip this vendor silently".
 */
interface PackagistFetcher
{
    /**
     * Returns the package names listed for the given vendor on Packagist,
     * or null when the result is unavailable for any reason (offline,
     * HTTP error, invalid JSON, unexpected response shape).
     *
     * @return list<string>|null
     */
    public function fetchPackages(string $vendor): ?array;
}
