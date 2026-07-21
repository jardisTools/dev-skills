<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Build;

use JardisTools\DevSkills\CheckCatalogDrift;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the drift-detection logic of CheckCatalogDrift.
 *
 * P4 acceptance criteria:
 *   - Checked-in SKILL.md matches → no exception (exit 0 in bin).
 *   - Artificially modified SKILL.md → RuntimeException (exit≠0 in bin).
 *   - Missing target file         → RuntimeException.
 */
final class CheckCatalogDriftTest extends TestCase
{
    private TempProject $project;

    private static string $repoRoot;

    private static string $manifestPath;

    private static string $checkedInSkillPath;

    public static function setUpBeforeClass(): void
    {
        self::$repoRoot          = (string) realpath(__DIR__ . '/../../../../');
        self::$manifestPath      = self::$repoRoot . '/catalog/manifest.json';
        self::$checkedInSkillPath = self::$repoRoot . '/skills/jardis-catalog/SKILL.md';
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
    // Happy-path: checked-in file matches what the generator would produce
    // -------------------------------------------------------------------------

    public function testCheckedInSkillMdMatchesGenerator(): void
    {
        // Must not throw — checked-in file is in sync with manifest.
        (new CheckCatalogDrift())(self::$manifestPath, self::$checkedInSkillPath);

        // If we reach here, no drift was detected.
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Drift detected: modified copy triggers RuntimeException
    // -------------------------------------------------------------------------

    public function testThrowsWhenCheckedInContentDiffersFromGenerated(): void
    {
        // Write a modified copy of the SKILL.md into a temp location.
        $checkedIn = (string) file_get_contents(self::$checkedInSkillPath);
        $modified  = $checkedIn . "\n<!-- injected line to simulate drift -->\n";
        $tempTarget = $this->project->writeFile('skills/jardis-catalog/SKILL.md', $modified);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Drift detected/i');

        (new CheckCatalogDrift())(self::$manifestPath, $tempTarget);
    }

    // -------------------------------------------------------------------------
    // Target file missing → RuntimeException
    // -------------------------------------------------------------------------

    public function testThrowsWhenTargetFileDoesNotExist(): void
    {
        $missingPath = $this->project->path('skills/jardis-catalog/SKILL.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        (new CheckCatalogDrift())(self::$manifestPath, $missingPath);
    }
}
