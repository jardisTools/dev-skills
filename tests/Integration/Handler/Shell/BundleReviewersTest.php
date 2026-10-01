<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Shell;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Handler\Shell\ParseReviewerSource;
use JardisTools\DevSkills\Handler\Shell\WriteReviewerShells;
use JardisTools\DevSkills\Handler\Validate\ParseSkillFrontmatter;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Checks the reviewer sources that ship in the bundle: all nineteen exist under the names of the
 * role table, every one is a valid source, and the real plugin root yields five shells for each.
 */
final class BundleReviewersTest extends TestCase
{
    private const ROLES = [
        'acceptance-gate',
        'existing-capability-check',
        'failure-diagnosis',
        'open-question-gate',
        'plan-review-architecture',
        'plan-review-ddd-tactics',
        'plan-review-frontend-a11y',
        'plan-review-frontend-architecture',
        'plan-review-frontend-tests',
        'plan-review-frontend-types',
        'plan-review-frontend-ux',
        'plan-review-packages',
        'plan-review-php',
        'plan-review-test-strategy',
        'prd-review-ddd-strategy',
        'prd-review-domain-expert',
        'prd-review-frontend-ux',
        'prd-review-skeptic',
        'stage-verifier',
    ];

    public function testNineteenSourcesWithTheNamesOfTheTable(): void
    {
        $entries = array_values(array_diff(scandir($this->sourceDir()) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);

        self::assertCount(19, $entries);
        self::assertSame(
            array_map(static fn (string $role): string => $role . '.md', self::ROLES),
            $entries,
        );
    }

    public function testEverySourceParses(): void
    {
        $parse = new ParseReviewerSource((new ParseSkillFrontmatter())->__invoke(...));

        foreach (self::ROLES as $role) {
            $source = $parse($role, (string) file_get_contents($this->sourceDir() . '/' . $role . '.md'));

            self::assertNotNull($source, $role . ' has no valid frontmatter.');
            self::assertSame($role, $source->name);
            self::assertNotSame('', $source->body, $role . ' has no body.');
        }
    }

    public function testEverySourceYieldsFiveShells(): void
    {
        $project = new TempProject('dev-skills-project-');
        try {
            $report = new InstallReport();
            AddonFactory::writeReviewerShells($this->pluginRoot())($project->root, $project->path('vendor'), $report);

            self::assertSame([], $report->warnings());
            self::assertCount(5, ShellFormat::cases());
            $written = 0;
            foreach (self::ROLES as $role) {
                foreach (ShellFormat::cases() as $format) {
                    self::assertFileExists($project->path($format->pathFor($role)), $format->name . ' shell of ' . $role);
                    ++$written;
                }
            }
            self::assertSame(19 * 5, $written);
        } finally {
            $project->cleanup();
        }
    }

    public function testNoSourceNamesAModel(): void
    {
        foreach (self::ROLES as $role) {
            $content = (string) file_get_contents($this->sourceDir() . '/' . $role . '.md');

            self::assertSame(
                0,
                preg_match('/\b(opus|sonnet|haiku|fable)\b/i', $content),
                $role . ' names a model; say "the next stronger model".',
            );
        }
    }

    public function testOpenQuestionGateListsTheVisibleSurfaceAmongTheUndelegable(): void
    {
        $content = (string) file_get_contents($this->sourceDir() . '/open-question-gate.md');
        $runStage = (string) file_get_contents($this->pluginRoot() . '/skills/process-run-stage/SKILL.md');
        $item = 'everything that changes the target picture or a visible surface (send the picture with it)';

        self::assertStringContainsString('- ' . $item . ";\n", $content);
        self::assertStringContainsString($item, $runStage);
    }

    private function pluginRoot(): string
    {
        return (string) realpath(dirname(__DIR__, 4));
    }

    private function sourceDir(): string
    {
        return $this->pluginRoot() . '/' . WriteReviewerShells::SOURCE_DIR;
    }
}
