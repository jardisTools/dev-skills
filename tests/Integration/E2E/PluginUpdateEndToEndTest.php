<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\E2E;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Tests\Support\ComposerFixture;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * Update from the released 1.3.6 to the candidate with real Composer runs. The
 * plugin code that reacts to a `composer update` is the code that was loaded
 * when Composer started, i.e. the OLD plugin; the test measures and pins down
 * which run migrates the project.
 */
final class PluginUpdateEndToEndTest extends TestCase
{
    private const RELEASE = '1.3.6';
    private const CANDIDATE = '1.4.0';

    private TempProject $project;
    private string $artifactDir;
    private string $fakeVendorRoot;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-update-e2e-');
        $this->artifactDir = $this->project->mkdir('artifacts');
        $this->fakeVendorRoot = (string) realpath(
            __DIR__ . '/../../Fixture/E2E/fake-vendor/jardisadapter-fakecache',
        );
        $repoRoot = (string) realpath(__DIR__ . '/../../..');

        LegacyFixture::archiveRelease($repoRoot, 'v' . self::RELEASE, self::RELEASE, $this->artifactDir);
        LegacyFixture::archiveCandidate($repoRoot, self::CANDIDATE, $this->artifactDir);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testUpdateFromReleaseMigratesInTheSecondRun(): void
    {
        // Baseline: what 1.3.6 itself leaves behind.
        $this->writeConsumer(self::RELEASE);
        ComposerFixture::runComposer($this->project, 'install');
        self::assertFileExists($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/adapter-fakecache/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents'));
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));

        // A local edit that must survive the migration as a backup.
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', "local edit\n");

        // Run 1: `composer update` to the candidate.
        $this->writeConsumer(self::CANDIDATE);
        ComposerFixture::runComposer($this->project, 'update jardis/dev-skills');
        $migratedInUpdate = is_file($this->project->path(Manifest::FILE));
        self::assertFalse(
            $migratedInUpdate,
            'Measured: the update run is still driven by the old plugin code and must not migrate.',
        );
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills'));

        // Run 2: `composer install` runs the candidate plugin.
        $output = ComposerFixture::runComposer($this->project, 'install');
        self::assertFileExists($this->project->path(Manifest::FILE), 'Measured: migrated in run 2.');
        $this->assertMigrated();
        $this->assertRedirects();
        self::assertStringContainsString(
            '18 bundle skills were renamed',
            $output,
            'The Composer output of the migrating run must carry the migration hint.',
        );

        // Run 3: nothing changes any more.
        $before = $this->snapshot();
        ComposerFixture::runComposer($this->project, 'install');
        self::assertSame($before, $this->snapshot());
    }

    private function assertMigrated(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->project->path(Manifest::FILE)),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(self::CANDIDATE, $manifest['pluginVersion']);
        self::assertArrayHasKey('.claude/skills/foundation-architecture', $manifest['paths']);
        self::assertArrayHasKey('.agents/skills/foundation-architecture', $manifest['paths']);
        self::assertArrayHasKey('.agents/skills/adapter-fakecache', $manifest['paths']);

        self::assertFileEquals(
            $this->project->path('.claude/skills/foundation-architecture/SKILL.md'),
            $this->project->path('.agents/skills/foundation-architecture/SKILL.md'),
        );
        self::assertNotSame(
            "local edit\n",
            file_get_contents($this->project->path('.claude/skills/foundation-architecture/SKILL.md')),
        );

        // No manifest in 1.3.x: the folder with the local edit is saved once before the redirect
        // skill takes its place, so the edit is kept under the plain backup name.
        self::assertSame(
            "local edit\n",
            file_get_contents($this->project->path('.claude/.jardis-backup/rules-architecture/SKILL.md')),
        );
        // One-time backup of every legacy bundle folder (no manifest, so no proof it is unchanged),
        // plus the relocated `.backup` sibling of the vendor skill. The update run of 1.3.6 installs the
        // renamed skills beside the old folders and leaves those untouched, so they carry no `.backup`.
        $backups = array_map('basename', glob($this->project->path('.claude/.jardis-backup/*')) ?: []);
        $names = array_unique(array_map(
            static fn (string $dir): string => preg_replace('/-\d{8}T\d{6}(-\d+)?$/', '', $dir) ?? $dir,
            $backups,
        ));
        sort($names);
        $expected = [...LegacyFixture::BUNDLE_NAMES, 'adapter-fakecache'];
        sort($expected);
        self::assertSame($expected, $names);
        self::assertCount(count(LegacyFixture::BUNDLE_NAMES) + 1, $backups);

        self::assertSame([], $this->backupSiblings(), 'No `*.backup` may remain in either skill folder.');
    }

    /**
     * Every old name is now a redirect skill in `.claude/skills` only.
     */
    private function assertRedirects(): void
    {
        self::assertCount(18, RenamedSkills::MAPPING);
        foreach (RenamedSkills::MAPPING as $old => $new) {
            $skillMd = $this->project->path('.claude/skills/' . $old . '/SKILL.md');
            self::assertFileExists($skillMd, 'Redirect skill missing: ' . $old);
            self::assertStringContainsString(
                sprintf("name: %s\ndescription: Renamed to %s. Load %s instead.\n", $old, $new, $new),
                (string) file_get_contents($skillMd),
            );
            self::assertFileExists($this->project->path('.claude/skills/' . $new . '/SKILL.md'));
            self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/' . $old));
        }
    }

    /**
     * @return list<string>
     */
    private function backupSiblings(): array
    {
        $found = [];
        foreach (['.claude/skills', '.agents/skills'] as $root) {
            foreach (glob($this->project->path($root) . '/*.backup*') ?: [] as $path) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $relative = substr($path, strlen($this->project->root) + 1);
            if (
                !$file->isFile()
                || str_starts_with($relative, 'vendor/')
                || str_starts_with($relative, '.composer-home/')
                || str_starts_with($relative, 'artifacts/')
            ) {
                continue;
            }
            $result[$relative] = sha1((string) file_get_contents($path));
        }
        ksort($result);

        return $result;
    }

    private function writeConsumer(string $pluginVersion): void
    {
        LegacyFixture::writeArtifactConsumerComposerJson(
            $this->project,
            $this->artifactDir,
            $this->fakeVendorRoot,
            $pluginVersion,
        );
    }
}
