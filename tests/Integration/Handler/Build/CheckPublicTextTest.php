<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Build;

use JardisTools\DevSkills\Data\PublicTextScope;
use JardisTools\DevSkills\Data\PublicTextViolation;
use JardisTools\DevSkills\Handler\Build\CheckPublicText;
use JardisTools\DevSkills\Handler\Build\ResolvePublicTextScope;
use JardisTools\DevSkills\Handler\Build\RunPublicTextGate;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Public-text gate: home-path regex always, denylist terms only when passed,
 * scope = tracked files without export-ignore. Fixtures use artificial tokens
 * only; the red path is assembled at runtime.
 */
final class CheckPublicTextTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testHomePathIsReportedWithFileAndLine(): void
    {
        $this->project->writeFile('a.md', "clean\nsee " . '/' . 'Users' . '/tester/x' . "\n");

        $result = (new CheckPublicText())($this->project->root, new PublicTextScope(['a.md']));

        self::assertCount(1, $result);
        self::assertSame('a.md', $result[0]->file);
        self::assertSame(2, $result[0]->line);
        self::assertSame(PublicTextViolation::KIND_HOME_PATH, $result[0]->kind);
    }

    public function testLinuxHomePathIsReported(): void
    {
        $this->project->writeFile('a.md', '/' . 'home' . '/tester/x');

        $result = (new CheckPublicText())($this->project->root, new PublicTextScope(['a.md']));

        self::assertCount(1, $result);
    }

    public function testDenylistTermIsReportedWithoutClearText(): void
    {
        $this->project->writeFile('a.md', "ok\nthis has ZZ-Token inside\n");

        $result = (new CheckPublicText())($this->project->root, new PublicTextScope(['a.md']), ['zz-token']);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->line);
        self::assertSame(PublicTextViolation::KIND_DENYLIST, $result[0]->kind);
        self::assertStringNotContainsStringIgnoringCase('zz-token', serialize($result));
    }

    public function testWithoutTermListOnlyRegexRuns(): void
    {
        $this->project->writeFile('a.md', 'this has zz-token inside');

        $result = (new CheckPublicText())($this->project->root, new PublicTextScope(['a.md']));

        self::assertSame([], $result);
    }

    public function testBlankTermsAreIgnored(): void
    {
        $this->project->writeFile('a.md', 'plain text');

        $result = (new CheckPublicText())($this->project->root, new PublicTextScope(['a.md']), ['', '  ']);

        self::assertSame([], $result);
    }

    public function testGreenFixtureYieldsNoViolations(): void
    {
        $this->project->writeFile('a.md', "# Title\nSee `<repo>/internal/x.go` and src/Users.php\n");

        $result = (new CheckPublicText())($this->project->root, new PublicTextScope(['a.md']), ['zz-token']);

        self::assertSame([], $result);
    }

    public function testRegexOnlyPathsIgnoreTerms(): void
    {
        $this->project->writeFile('f.txt', "zz-token\n" . '/' . 'Users' . '/tester/x');

        $result = (new CheckPublicText())(
            $this->project->root,
            new PublicTextScope([], ['f.txt']),
            ['zz-token'],
        );

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->line);
        self::assertSame(PublicTextViolation::KIND_HOME_PATH, $result[0]->kind);
    }

    public function testMissingAndBinaryFilesAreSkipped(): void
    {
        $this->project->writeFile('bin.dat', "\0" . '/' . 'Users' . '/tester/x');

        $result = (new CheckPublicText())(
            $this->project->root,
            new PublicTextScope(['missing.md', 'bin.dat']),
        );

        self::assertSame([], $result);
    }

    public function testScopeContainsNonExportIgnoredAndExcludesExportIgnored(): void
    {
        $this->project->writeFile('.gitattributes', "/hidden export-ignore\n");
        $this->project->writeFile('newdir/a.md', 'x');
        $this->project->writeFile('hidden/b.md', 'x');
        $this->project->writeFile('docs/SKILL-FORMAT.md', 'x');
        $this->project->writeFile('docs/internal.md', 'x');
        $this->project->writeFile('tests/Fixture/f.txt', 'x');
        $this->project->writeFile('untracked-extra/overview-not.md', 'x');
        $this->git('init', '-q');
        $this->git('add', '.gitattributes', 'newdir', 'hidden', 'docs', 'tests');
        // Published docs enter the scope by path, even before they are tracked.
        $this->project->writeFile('docs/index.html', 'x');
        $this->project->writeFile('docs/overview.de.html', 'x');

        $scope = (new ResolvePublicTextScope())($this->project->root);

        self::assertContains('newdir/a.md', $scope->paths);
        self::assertContains('.gitattributes', $scope->paths);
        self::assertContains('docs/SKILL-FORMAT.md', $scope->paths);
        self::assertContains('docs/index.html', $scope->paths);
        self::assertContains('docs/overview.de.html', $scope->paths);
        self::assertNotContains('hidden/b.md', $scope->paths);
        self::assertNotContains('untracked-extra/overview-not.md', $scope->paths);
        self::assertSame(['tests/Fixture/f.txt'], $scope->regexOnlyPaths);
    }

    public function testScopeSkipsTrackedButDeletedPaths(): void
    {
        $this->project->writeFile('gone.md', 'x');
        $this->project->writeFile('stay.md', 'x');
        $this->git('init', '-q');
        $this->git('add', '.');
        unlink($this->project->root . '/gone.md');

        $scope = (new ResolvePublicTextScope())($this->project->root);

        self::assertSame(['stay.md'], $scope->paths);
    }

    public function testEmptyGitScopeThrows(): void
    {
        $this->git('init', '-q');

        $this->expectException(\RuntimeException::class);
        (new ResolvePublicTextScope())($this->project->root);
    }

    public function testNonGitDirectoryThrows(): void
    {
        $this->project->writeFile('a.md', 'x');

        $this->expectException(\RuntimeException::class);
        (new ResolvePublicTextScope())($this->project->root);
    }

    public function testScopeWithoutScannablePathThrows(): void
    {
        $this->project->writeFile('gone.md', 'x');
        $this->git('init', '-q');
        $this->git('add', '.');
        unlink($this->project->root . '/gone.md');

        $this->expectException(\RuntimeException::class);
        (new ResolvePublicTextScope())($this->project->root);
    }

    public function testRequiredButEmptyDenylistIsAnError(): void
    {
        $this->project->writeFile('a.md', 'plain');

        $result = (new RunPublicTextGate())($this->project->root, new PublicTextScope(['a.md']), " \n", true);

        self::assertNotNull($result->error);
        self::assertSame(1, $result->exitCode());
        self::assertSame(0, $result->scanned);
    }

    public function testNotRequiredEmptyDenylistRunsRegexOnly(): void
    {
        $this->project->writeFile('a.md', 'has zz-token inside');

        $result = (new RunPublicTextGate())($this->project->root, new PublicTextScope(['a.md']), '', false);

        self::assertNull($result->error);
        self::assertTrue($result->regexOnly);
        self::assertSame([], $result->violations);
        self::assertSame(0, $result->exitCode());
        self::assertSame(1, $result->scanned);
    }

    public function testSuppliedDenylistIsApplied(): void
    {
        $this->project->writeFile('a.md', 'has zz-token inside');

        $result = (new RunPublicTextGate())($this->project->root, new PublicTextScope(['a.md']), "other\nzz-token\n", true);

        self::assertFalse($result->regexOnly);
        self::assertCount(1, $result->violations);
        self::assertSame(PublicTextViolation::KIND_DENYLIST, $result->violations[0]->kind);
        self::assertSame(1, $result->exitCode());
    }

    public function testHomePathViolationFailsRegexOnlyRun(): void
    {
        $this->project->writeFile('a.md', '/' . 'Users' . '/tester/x');

        $result = (new RunPublicTextGate())($this->project->root, new PublicTextScope(['a.md']), '', false);

        self::assertSame(1, $result->exitCode());
    }

    public function testCleanScopeWithDenylistExitsZero(): void
    {
        $this->project->writeFile('a.md', 'plain');

        $result = (new RunPublicTextGate())($this->project->root, new PublicTextScope(['a.md']), 'zz-token', true);

        self::assertSame(0, $result->exitCode());
    }

    private function git(string ...$args): void
    {
        $cmd = 'git -c safe.directory=* -C ' . escapeshellarg($this->project->root)
            . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
