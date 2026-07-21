<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Build;

use JardisTools\DevSkills\Handler\Build\CheckCatalogPackagist;
use JardisTools\DevSkills\Tests\Support\FakePackagistFetcher;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the additive Packagist check logic of CheckCatalogPackagist.
 *
 * All tests use FakePackagistFetcher — no real network calls are made.
 *
 * P4 acceptance criteria:
 *   - Extra package on Packagist (not in manifest) → warning returned.
 *   - Fetcher returns null (offline / failure)     → no warning, no exception.
 *   - Fetcher returns empty list                   → no warning, no exception.
 *   - Packagist matches manifest exactly           → no warning.
 *   - jardistools/dev-skills is on Packagist       → excluded, no warning.
 */
final class CheckCatalogPackagistTest extends TestCase
{
    private TempProject $project;

    private static string $repoRoot;

    private static string $manifestPath;

    public static function setUpBeforeClass(): void
    {
        self::$repoRoot    = (string) realpath(__DIR__ . '/../../../../');
        self::$manifestPath = self::$repoRoot . '/catalog/manifest.json';
    }

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    // -------------------------------------------------------------------------
    // Extra package on Packagist → warning
    // -------------------------------------------------------------------------

    public function testReturnsWarningForPackageMissingFromManifest(): void
    {
        $fetcher = new FakePackagistFetcher([
            'jardisadapter' => ['jardisadapter/cache', 'jardisadapter/new-package-not-in-manifest'],
        ]);

        $warnings = (new CheckCatalogPackagist())(self::$manifestPath, $fetcher);

        // At least one warning mentions the missing package.
        $messages = implode("\n", $warnings);
        self::assertStringContainsString(
            'jardisadapter/new-package-not-in-manifest',
            $messages,
            'A package on Packagist but absent from the manifest must trigger a warning.',
        );

        // jardisadapter/cache IS in the manifest → must NOT appear in warnings.
        self::assertStringNotContainsString(
            'jardisadapter/cache',
            $messages,
            'A package already in the manifest must not trigger a warning.',
        );
    }

    // -------------------------------------------------------------------------
    // Offline / fetcher returns null → silent, no warnings, exit 0
    // -------------------------------------------------------------------------

    public function testReturnsNoWarningWhenFetcherReturnsNull(): void
    {
        // All vendors return null → simulates completely offline scenario.
        $fetcher  = new FakePackagistFetcher([]);
        $warnings = (new CheckCatalogPackagist())(self::$manifestPath, $fetcher);

        self::assertSame(
            [],
            $warnings,
            'An offline fetcher (null for all vendors) must produce zero warnings.',
        );
    }

    // -------------------------------------------------------------------------
    // Fetcher returns empty list → no false-alarm warning, exit 0
    // -------------------------------------------------------------------------

    public function testReturnsNoWarningWhenFetcherReturnsEmptyList(): void
    {
        $fetcher = new FakePackagistFetcher([
            'jardiscore'    => [],
            'jardisadapter' => [],
            'jardissupport' => [],
            'jardistools'   => [],
        ]);

        $warnings = (new CheckCatalogPackagist())(self::$manifestPath, $fetcher);

        self::assertSame(
            [],
            $warnings,
            'An empty package list for all vendors must produce zero warnings.',
        );
    }

    // -------------------------------------------------------------------------
    // Packagist matches manifest exactly → no warnings
    // -------------------------------------------------------------------------

    public function testReturnsNoWarningWhenPackagistMatchesManifest(): void
    {
        // Use a subset of real manifest packages — all are present, nothing extra.
        $fetcher = new FakePackagistFetcher([
            'jardisadapter' => ['jardisadapter/cache', 'jardisadapter/logger'],
            'jardiscore'    => ['jardiscore/kernel'],
        ]);

        $warnings = (new CheckCatalogPackagist())(self::$manifestPath, $fetcher);

        self::assertSame(
            [],
            $warnings,
            'When all Packagist packages are already in the manifest, no warnings must be emitted.',
        );
    }

    // -------------------------------------------------------------------------
    // jardistools/dev-skills is excluded even if Packagist returns it
    // -------------------------------------------------------------------------

    public function testExcludesDevSkillsPackageFromWarnings(): void
    {
        $fetcher = new FakePackagistFetcher([
            'jardistools' => ['jardistools/dev-skills', 'jardistools/dbschema'],
        ]);

        $warnings = (new CheckCatalogPackagist())(self::$manifestPath, $fetcher);
        $messages = implode("\n", $warnings);

        self::assertStringNotContainsString(
            'jardistools/dev-skills',
            $messages,
            'jardistools/dev-skills is explicitly excluded and must never appear in warnings.',
        );
    }

    // -------------------------------------------------------------------------
    // Custom manifest in temp: missing package detected without real manifest
    // -------------------------------------------------------------------------

    public function testDetectsMissingPackageAgainstCustomManifest(): void
    {
        $manifest = json_encode([
            [
                'package'          => 'jardisadapter/cache',
                'capability'       => 'caching',
                'use_when'         => 'when caching',
                'composer_require' => 'composer require jardisadapter/cache',
            ],
        ]);

        $manifestPath = $this->project->writeFile('catalog/manifest.json', (string) $manifest);

        $fetcher = new FakePackagistFetcher([
            'jardisadapter' => ['jardisadapter/cache', 'jardisadapter/logger'],
        ]);

        $warnings = (new CheckCatalogPackagist())($manifestPath, $fetcher);
        $messages = implode("\n", $warnings);

        self::assertStringContainsString(
            'jardisadapter/logger',
            $messages,
            'jardisadapter/logger is on Packagist but missing from the custom manifest → must warn.',
        );

        self::assertStringNotContainsString(
            'jardisadapter/cache',
            $messages,
            'jardisadapter/cache is in the manifest → must not warn.',
        );
    }
}
