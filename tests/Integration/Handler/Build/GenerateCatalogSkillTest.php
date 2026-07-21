<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Build;

use JardisTools\DevSkills\GenerateCatalogSkill;
use JardisTools\DevSkills\Handler\Build\ParseManifest;
use JardisTools\DevSkills\Handler\Validate\ValidateSkillMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class GenerateCatalogSkillTest extends TestCase
{
    private TempProject $project;

    /** Resolved once per test run; constant for the lifetime of the process. */
    private static string $repoRoot;

    private static string $manifestPath;

    public static function setUpBeforeClass(): void
    {
        self::$repoRoot     = (string) realpath(__DIR__ . '/../../../../');
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
    // Happy-path
    // -------------------------------------------------------------------------

    public function testGeneratesValidSkillMdFromRealManifest(): void
    {
        $target = $this->project->path('skills/jardis-catalog/SKILL.md');

        (new GenerateCatalogSkill())(self::$manifestPath, $target);

        self::assertFileExists($target, 'SKILL.md must be created at the given target path.');

        $errors = (new ValidateSkillMd())($target);
        self::assertSame(
            [],
            $errors,
            "Generated SKILL.md failed validation:\n" . implode("\n", $errors),
        );
    }

    public function testGeneratedSkillMdContainsAllManifestPackages(): void
    {
        $target = $this->project->path('skills/jardis-catalog/SKILL.md');

        (new GenerateCatalogSkill())(self::$manifestPath, $target);

        $content = (string) file_get_contents($target);

        /** @var list<array{package: string}> $entries */
        $entries = json_decode((string) file_get_contents(self::$manifestPath), true) ?: [];

        foreach ($entries as $entry) {
            self::assertStringContainsString(
                $entry['package'],
                $content,
                "Package '{$entry['package']}' must appear in the generated SKILL.md.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Determinism
    // -------------------------------------------------------------------------

    public function testTwoRunsProduceByteIdenticalOutput(): void
    {
        $target1 = $this->project->path('run1/skills/jardis-catalog/SKILL.md');
        $target2 = $this->project->path('run2/skills/jardis-catalog/SKILL.md');

        $generator = new GenerateCatalogSkill();
        $generator(self::$manifestPath, $target1);
        $generator(self::$manifestPath, $target2);

        $content1 = file_get_contents($target1);
        $content2 = file_get_contents($target2);

        self::assertNotFalse($content1, 'First run output must be readable.');
        self::assertNotFalse($content2, 'Second run output must be readable.');
        self::assertSame(
            $content1,
            $content2,
            'Two consecutive runs must produce byte-identical SKILL.md output.',
        );
    }

    // -------------------------------------------------------------------------
    // Negative tests — manifest errors
    // -------------------------------------------------------------------------

    public function testThrowsWhenManifestPathDoesNotExist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Manifest file not found/');

        (new ParseManifest())($this->project->path('nonexistent/manifest.json'));
    }

    public function testThrowsWhenManifestIsNotValidJson(): void
    {
        $badManifest = $this->project->writeFile('catalog/manifest.json', 'not valid json at all {');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/i');

        (new ParseManifest())($badManifest);
    }

    public function testThrowsWhenManifestRootIsNotArray(): void
    {
        $badManifest = $this->project->writeFile('catalog/manifest.json', '"just a string"');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be a JSON array/i');

        (new ParseManifest())($badManifest);
    }

    public function testThrowsWhenEntryIsMissingRequiredField(): void
    {
        $content = json_encode([
            [
                'package'    => 'jardisadapter/cache',
                'capability' => 'caching',
                // 'use_when' is deliberately omitted
                'composer_require' => 'composer require jardisadapter/cache',
            ],
        ]);

        $badManifest = $this->project->writeFile('catalog/manifest.json', (string) $content);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required field "use_when"/i');

        (new ParseManifest())($badManifest);
    }

    public function testThrowsWhenRequiredFieldIsEmpty(): void
    {
        $content = json_encode([
            [
                'package'          => '',
                'capability'       => 'something',
                'use_when'         => 'whenever',
                'composer_require' => 'composer require jardisadapter/cache',
            ],
        ]);

        $badManifest = $this->project->writeFile('catalog/manifest.json', (string) $content);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be a non-empty string/i');

        (new ParseManifest())($badManifest);
    }
}
