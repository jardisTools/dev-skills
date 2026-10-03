<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Build;

use JardisTools\DevSkills\Handler\Build\CheckChangelogTop;
use JardisTools\DevSkills\Tests\Support\RunScript;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Changelog gate: the first version heading of the file is the version about to be tagged.
 * The date is not checked. Fixtures use artificial changelogs; one run reads the real file.
 */
final class CheckChangelogTopTest extends TestCase
{
    private const HEADER = "# Changelog\n\nAll notable changes are documented in this file.\n\n";

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testMatchingTopVersionIsGreen(): void
    {
        $this->project->writeFile(
            'CHANGELOG.md',
            self::HEADER . "## [2.0.0] - 2030-01-01\n\n### Added\n- x\n\n## [1.9.0] - 2029-12-01\n",
        );

        $result = (new CheckChangelogTop())($this->read(), '2.0.0');

        self::assertNull($result);
    }

    public function testDateOfTheTopHeadingIsNotChecked(): void
    {
        $this->project->writeFile('CHANGELOG.md', self::HEADER . "## [2.0.0]\n\n- x\n");

        self::assertNull((new CheckChangelogTop())($this->read(), '2.0.0'));
    }

    public function testUnreleasedOnTopIsRedWithExpectedAndFound(): void
    {
        $this->project->writeFile(
            'CHANGELOG.md',
            self::HEADER . "## [Unreleased]\n\n## [2.0.0] - 2030-01-01\n",
        );

        $result = (new CheckChangelogTop())($this->read(), '2.0.0');

        self::assertNotNull($result);
        self::assertStringContainsString('[2.0.0]', $result);
        self::assertStringContainsString('[Unreleased]', $result);
    }

    public function testOtherVersionOnTopIsRedWithExpectedAndFound(): void
    {
        $this->project->writeFile(
            'CHANGELOG.md',
            self::HEADER . "## [1.3.6] - 2026-09-29\n\n### Changed\n- x\n",
        );

        $result = (new CheckChangelogTop())($this->read(), '1.4.0');

        self::assertNotNull($result);
        self::assertStringContainsString('[1.4.0]', $result);
        self::assertStringContainsString('[1.3.6]', $result);
    }

    public function testRequestedVersionFurtherDownDoesNotCount(): void
    {
        $this->project->writeFile(
            'CHANGELOG.md',
            self::HEADER . "## [Unreleased]\n\n## [1.4.0] - 2026-10-01\n",
        );

        self::assertNotNull((new CheckChangelogTop())($this->read(), '1.4.0'));
    }

    public function testFileWithoutVersionHeadingIsRed(): void
    {
        $this->project->writeFile('CHANGELOG.md', self::HEADER . "Nothing released yet.\n\n### Added\n- x\n");

        $result = (new CheckChangelogTop())($this->read(), '1.4.0');

        self::assertNotNull($result);
        self::assertStringContainsString('[1.4.0]', $result);
        self::assertStringContainsString('no version heading', $result);
    }

    public function testEmptyFileIsRed(): void
    {
        self::assertNotNull((new CheckChangelogTop())('', '1.4.0'));
    }

    public function testCrLfLineEndingsAreAccepted(): void
    {
        $content = str_replace("\n", "\r\n", self::HEADER . "## [2.0.0] - 2030-01-01\n\n- x\n");

        self::assertNull((new CheckChangelogTop())($content, '2.0.0'));
    }

    public function testRealChangelogCarriesTheVersionToTag(): void
    {
        $content = (string) file_get_contents($this->repoRoot() . '/CHANGELOG.md');

        $result = (new CheckChangelogTop())($content, '1.7.1');

        self::assertNull($result, (string) $result);
    }

    public function testScriptExitsZeroOnGreenOneOnRedTwoWithoutVersion(): void
    {
        $this->project->mkdir('bin');
        copy($this->repoRoot() . '/bin/check-changelog-top.php', $this->project->path('bin/check-changelog-top.php'));
        $this->project->writeFile('vendor/autoload.php', "<?php\nrequire " . var_export(
            $this->repoRoot() . '/vendor/autoload.php',
            true,
        ) . ";\n");
        $this->project->writeFile('CHANGELOG.md', self::HEADER . "## [Unreleased]\n\n## [2.0.0] - 2030-01-01\n");
        $script = $this->project->path('bin/check-changelog-top.php');

        $red = RunScript::run($script, $this->project->root, ['2.0.0']);
        self::assertSame(1, $red['exit']);
        self::assertStringContainsString('[Unreleased]', $red['stderr']);

        $this->project->writeFile('CHANGELOG.md', self::HEADER . "## [2.0.0] - 2030-01-01\n");
        $green = RunScript::run($script, $this->project->root, ['2.0.0']);
        self::assertSame(0, $green['exit']);

        $usage = RunScript::run($script, $this->project->root);
        self::assertSame(2, $usage['exit']);
        self::assertStringContainsString('usage', $usage['stderr']);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->project->path('CHANGELOG.md'));
    }

    private function repoRoot(): string
    {
        return (string) realpath(__DIR__ . '/../../../..');
    }
}
