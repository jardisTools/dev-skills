<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

/**
 * Closure: additively checks whether any Packagist-listed Jardis package
 * is missing from the local catalog manifest.
 *
 * Only reports packages that are ON Packagist but ABSENT from the manifest
 * (additive direction). The reverse (in manifest, not on Packagist) is not
 * reported — that is expected for curated entries not yet published.
 *
 * Any fetcher failure (null / empty result) silently skips that vendor.
 * This closure never throws and always returns; exit code stays 0.
 *
 * @return list<string> Warning messages; empty when no packages are missing.
 */
final class CheckCatalogPackagist
{
    /** @var list<string> */
    private const VENDORS = ['jardiscore', 'jardisadapter', 'jardissupport', 'jardistools'];

    /**
     * Packages that ARE on Packagist but intentionally excluded from the
     * catalog (e.g. the plugin itself, or internal tooling packages).
     *
     * @var list<string>
     */
    private const EXCLUDED = ['jardistools/dev-skills'];

    /**
     * @return list<string>
     */
    public function __invoke(string $manifestPath, PackagistFetcher $fetcher): array
    {
        $manifestPackages = $this->readManifestPackages($manifestPath);
        $warnings         = [];

        foreach (self::VENDORS as $vendor) {
            $packagistPackages = $fetcher->fetchPackages($vendor);

            if ($packagistPackages === null || $packagistPackages === []) {
                continue;
            }

            foreach ($packagistPackages as $package) {
                if (in_array($package, self::EXCLUDED, true)) {
                    continue;
                }

                if (!in_array($package, $manifestPackages, true)) {
                    $warnings[] = sprintf(
                        '[catalog-packagist] WARNING: %s is on Packagist but missing from catalog/manifest.json',
                        $package,
                    );
                }
            }
        }

        return $warnings;
    }

    /**
     * Extracts the package names from the manifest JSON.
     * Returns an empty list on any IO or parse failure (error-tolerant).
     *
     * @return list<string>
     */
    private function readManifestPackages(string $manifestPath): array
    {
        if (!is_file($manifestPath)) {
            return [];
        }

        $raw = file_get_contents($manifestPath);

        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $packages = [];

        foreach ($decoded as $item) {
            if (
                is_array($item)
                && isset($item['package'])
                && is_string($item['package'])
                && $item['package'] !== ''
            ) {
                $packages[] = $item['package'];
            }
        }

        return $packages;
    }
}
