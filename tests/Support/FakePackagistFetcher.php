<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

use JardisTools\DevSkills\Handler\Build\PackagistFetcher;

/**
 * Test double for PackagistFetcher.
 *
 * Pre-seeded with a vendor→packages map; returns null for any vendor
 * not explicitly configured, matching the "offline / unavailable" contract.
 */
final class FakePackagistFetcher implements PackagistFetcher
{
    /**
     * @param array<string, list<string>|null> $data vendor → package list (null = simulate failure)
     */
    public function __construct(private readonly array $data = [])
    {
    }

    /**
     * @return list<string>|null
     */
    public function fetchPackages(string $vendor): ?array
    {
        return $this->data[$vendor] ?? null;
    }
}
