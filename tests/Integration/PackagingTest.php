<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Guards the Composer dist manifest defined by `.gitattributes`.
 *
 * Composer ships the `dist` archive to consumers; `export-ignore` decides
 * what lands in their `vendor/jardis/dev-skills/`. A forgotten `export-ignore`
 * on a new dev path silently bloats every consumer project; an accidental
 * `export-ignore` on `src/` or `skills/` silently breaks the package. Both
 * failure modes only surface at release time — this test catches them in CI.
 *
 * Measured via `git check-attr` against the working tree (index + worktree),
 * so it is accurate even before the change is committed. `safe.directory=*` keeps
 * the verdict independent of who owns the checkout (a container bind mount hands
 * ownership over asynchronously; without it git refuses with "dubious ownership"
 * depending on timing and test order).
 */
final class PackagingTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        $this->pluginRoot = (string) realpath(__DIR__ . '/../..');
        if (!is_file($this->pluginRoot . '/.gitattributes')) {
            self::fail('.gitattributes not resolvable at plugin root: ' . $this->pluginRoot);
        }
    }

    /**
     * Runtime essentials the consumer needs — must NOT be export-ignored.
     */
    public function testRuntimeEssentialsAreShipped(): void
    {
        foreach (['src', 'skills', 'AGENTS.md', 'composer.json', 'README.md', 'LICENSE.md'] as $path) {
            self::assertSame(
                'unspecified',
                $this->exportIgnore($path),
                sprintf('"%s" must ship in the dist archive but is export-ignored.', $path),
            );
        }
    }

    /**
     * The reviewer sources are read from the plugin at install time, so their folder must be in the dist
     * archive (it exists from the moment the prompts are shipped; the attribute decides before that).
     */
    public function testReviewerSourcePathIsShipped(): void
    {
        foreach (['skills/process-review-board', 'skills/process-review-board/reviewers'] as $path) {
            self::assertSame(
                'unspecified',
                $this->exportIgnore($path),
                sprintf('"%s" must ship in the dist archive but is export-ignored.', $path),
            );
        }
    }

    /**
     * Development / CI artefacts — must be export-ignored from the dist archive.
     */
    public function testDevelopmentArtefactsAreExcluded(): void
    {
        $devPaths = [
            'docs', 'tests', 'bin', 'support', '.github',
            'REQUIREMENT.md', 'phpunit.xml', 'phpstan.neon', 'phpcs.xml',
            'Makefile', '.env.example', 'composer.lock',
        ];

        foreach ($devPaths as $path) {
            self::assertSame(
                'set',
                $this->exportIgnore($path),
                sprintf('"%s" is a dev-only path and must be export-ignored.', $path),
            );
        }
    }

    /**
     * The pool check is delivered from `scripts/` (and linked as a Composer bin): it must ship, while the
     * dev-only `bin/` folder stays out of the dist archive, and the Composer bin entry points at the shipped path.
     */
    public function testScriptsFolderIsShippedAndBinStaysExcluded(): void
    {
        foreach (['scripts', 'scripts/pool-check.php'] as $path) {
            self::assertSame(
                'unspecified',
                $this->exportIgnore($path),
                sprintf('"%s" must ship in the dist archive but is export-ignored.', $path),
            );
        }
        self::assertSame('set', $this->exportIgnore('bin'), '"bin" is dev-only and must be export-ignored.');

        $composer = json_decode((string) file_get_contents($this->pluginRoot . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame(['scripts/pool-check.php'], $composer['bin'] ?? null);
        self::assertFileExists($this->pluginRoot . '/scripts/pool-check.php');
    }

    /**
     * The commit-msg hook, its installer and the CI range check are installed into customer projects from the plugin folder,
     * so all three must ship in the dist archive, next to the pool check.
     */
    public function testCommitHookScriptsAreShipped(): void
    {
        foreach (['scripts/commit-msg', 'scripts/check-commit-messages', 'scripts/install-commit-msg-hook'] as $path) {
            self::assertFileExists($this->pluginRoot . '/' . $path);
            self::assertSame(
                'unspecified',
                $this->exportIgnore($path),
                sprintf('"%s" must ship in the dist archive but is export-ignored.', $path),
            );
        }
    }

    /**
     * The archive content, not only the attributes: what a consumer unpacks has the runtime folders
     * and none of the development folders. Measured is the committed state (`git archive HEAD`),
     * exactly what Composer downloads as dist.
     */
    public function testHeadArchiveCarriesTheRuntimeFoldersAndNoDevelopmentFolder(): void
    {
        $project = new TempProject('dev-skills-packaging-');
        try {
            LegacyFixture::archiveHead($this->pluginRoot, '9.9.9', $project->root);

            $zip = new \ZipArchive();
            self::assertTrue($zip->open($project->path('dev-skills-9.9.9.zip')) === true, 'Could not open the HEAD archive.');
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entries[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();

            foreach (['scripts', 'router', 'skills', 'catalog'] as $folder) {
                $files = array_filter($entries, static fn(string $entry): bool => str_starts_with($entry, $folder . '/') && !str_ends_with($entry, '/'));
                self::assertNotEmpty($files, sprintf('"%s/" must be delivered with at least one file.', $folder));
            }
            foreach (['bin', 'docs', 'tests'] as $folder) {
                $inside = array_filter($entries, static fn(string $entry): bool => $entry === $folder || str_starts_with($entry, $folder . '/'));
                self::assertSame([], array_values($inside), sprintf('"%s/" must not be delivered.', $folder));
            }
        } finally {
            $project->cleanup();
        }
    }

    /**
     * Returns git's export-ignore verdict for $path: "set" or "unspecified".
     */
    private function exportIgnore(string $path): string
    {
        $cmd = sprintf(
            "git -c 'safe.directory=*' -C %s check-attr export-ignore -- %s 2>&1",
            escapeshellarg($this->pluginRoot),
            escapeshellarg($path),
        );

        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $line = implode("\n", $output);

        if ($exitCode !== 0) {
            self::fail(sprintf('git check-attr failed for "%s": %s', $path, $line));
        }

        // Output format: "<path>: export-ignore: <value>"
        $value = substr($line, (int) strrpos($line, ': ') + 2);

        return trim($value);
    }
}
