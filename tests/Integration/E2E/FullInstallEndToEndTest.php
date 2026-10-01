<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\E2E;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Install\BuildClaudeMdContent;
use JardisTools\DevSkills\Handler\Install\LoadRouterText;
use JardisTools\DevSkills\Tests\Support\ComposerFixture;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The full install with real Composer runs, taken from `git archive HEAD` as an `artifact`
 * repository: exactly what a consumer downloads, `export-ignore` included. Only committed files
 * are in the archive, so these tests measure the committed state (`git archive HEAD`), not the
 * working tree; uncommitted changes are checked by the rest of the suite.
 *
 * Covers the install picture (33 skills in both folders, 19 reviewers in five formats, router,
 * CLAUDE.md block, Gemini entry, manifest), the repeat run, the update from 1.3.6 with the
 * redirects, the removal, and the untouched knowledge pool and project profile throughout.
 */
final class FullInstallEndToEndTest extends TestCase
{
    private const RELEASE = '1.3.6';
    private const CANDIDATE = '1.4.0';
    private const POOL_SENTENCE = 'Wissenspool: `.claude/wissen/INDEX.md` — vor Entscheiden lesen, Vermerk-Pflicht';
    private const POOL_INDEX = '.claude/wissen/INDEX.md';
    private const PROFILE = '.claude/PROJECT_PROFILE.md';
    private const VENDOR_SKILL = 'adapter-fakecache';

    private TempProject $project;
    private string $repoRoot;
    private string $artifactDir;
    private string $fakeVendorRoot;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-full-e2e-');
        $this->artifactDir = $this->project->mkdir('artifacts');
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->fakeVendorRoot = (string) realpath(__DIR__ . '/../../Fixture/E2E/fake-vendor/jardisadapter-fakecache');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testFullInstallFromTheHeadArchiveYieldsEveryFileOfTheTargetPicture(): void
    {
        $this->writePoolAndProfile();
        $this->writeConsumer(self::CANDIDATE, []);
        LegacyFixture::archiveHead($this->repoRoot, self::CANDIDATE, $this->artifactDir);

        $output = ComposerFixture::runComposer($this->project, 'install');

        // The archive is what a consumer gets: development folders are export-ignored.
        self::assertFileExists($this->project->path('vendor/jardis/dev-skills/src/Plugin.php'));
        foreach (['tests', 'docs', 'bin', 'support'] as $ignored) {
            self::assertDirectoryDoesNotExist($this->project->path('vendor/jardis/dev-skills/' . $ignored), $ignored);
        }

        // 33 skills, both folders, file for file as shipped; the vendor skill beside them; no redirect.
        $names = $this->bundleNames();
        self::assertCount(33, $names);
        foreach (['.claude/skills', '.agents/skills'] as $root) {
            self::assertSame(
                $this->sorted([...$names, self::VENDOR_SKILL]),
                $this->folderNames($root),
                $root . ' must hold the bundle plus the vendor skill and nothing else.',
            );
            foreach ($names as $name) {
                self::assertSame(
                    TreeSnapshot::of($this->project->path('vendor/jardis/dev-skills/skills/' . $name)),
                    TreeSnapshot::of($this->project->path($root . '/' . $name)),
                    $root . '/' . $name . ' differs from the shipped skill.',
                );
            }
        }
        self::assertStringNotContainsString('were renamed', $output);

        // 19 reviewers x 5 shell formats.
        $roles = $this->reviewerRoles();
        self::assertCount(19, $roles);
        self::assertCount(5, ShellFormat::cases());
        foreach (ShellFormat::cases() as $format) {
            foreach ($roles as $role) {
                self::assertFileExists($this->project->path($format->pathFor($role)), $format->pathFor($role));
            }
            self::assertCount(
                19,
                glob($this->project->path(dirname($format->pathFor('x'))) . '/*') ?: [],
                'No further file may stand beside the shells of ' . $format->name,
            );
        }

        // AGENTS.md: managed block, the shipped router text, the pool sentence once, the vendor body.
        $agentsMd = (string) file_get_contents($this->project->path('AGENTS.md'));
        $router = (new LoadRouterText())($this->project->path('vendor/jardis/dev-skills'));
        self::assertNotSame('', $router);
        self::assertStringContainsString(AnalyzeAgentsMd::HEADER, $agentsMd);
        self::assertStringContainsString(AnalyzeAgentsMd::FOOTER, $agentsMd);
        self::assertStringContainsString($router, $agentsMd);
        self::assertSame(1, substr_count($agentsMd, self::POOL_SENTENCE));
        self::assertSame(1, substr_count($agentsMd, 'Wissenspool'));
        self::assertStringContainsString('FAKE_VENDOR_AGENTS_BODY_MARKER', $agentsMd);

        // CLAUDE.md: created with the import block and nothing else.
        self::assertSame(
            implode("\n", [AnalyzeAgentsMd::HEADER, BuildClaudeMdContent::IMPORT_LINE, AnalyzeAgentsMd::FOOTER]) . "\n",
            file_get_contents($this->project->path('CLAUDE.md')),
        );

        // Gemini context lists AGENTS.md.
        $gemini = json_decode((string) file_get_contents($this->project->path('.gemini/settings.json')), true, 32, JSON_THROW_ON_ERROR);
        self::assertContains('AGENTS.md', $gemini['context']['fileName']);

        // Manifest: version, every skill folder of both targets, the shells and the files the plugin created.
        $manifest = json_decode((string) file_get_contents($this->project->path(Manifest::FILE)), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(self::CANDIDATE, $manifest['pluginVersion']);
        foreach ([...$names, self::VENDOR_SKILL] as $name) {
            self::assertArrayHasKey('.claude/skills/' . $name, $manifest['paths']);
            self::assertArrayHasKey('.agents/skills/' . $name, $manifest['paths']);
        }
        self::assertCount(2 * (33 + 1), $manifest['paths']);
        foreach (ShellFormat::cases() as $format) {
            foreach ($roles as $role) {
                self::assertTrue($manifest['selfSet'][$format->pathFor($role)]['fileCreated'] ?? false, $format->pathFor($role));
            }
        }
        foreach (['AGENTS.md', 'CLAUDE.md', '.gemini/settings.json'] as $created) {
            self::assertTrue($manifest['selfSet'][$created]['fileCreated'] ?? false, $created);
        }

        // Pool and profile of the project were never touched.
        $this->assertPoolAndProfileUntouched();
    }

    public function testSecondInstallRunLeavesTheTreeByteIdentical(): void
    {
        $this->writePoolAndProfile();
        $this->writeConsumer(self::CANDIDATE, []);
        LegacyFixture::archiveHead($this->repoRoot, self::CANDIDATE, $this->artifactDir);
        ComposerFixture::runComposer($this->project, 'install');
        $before = $this->projectTree();
        self::assertNotEmpty($before);

        $output = ComposerFixture::runComposer($this->project, 'install');

        self::assertSame($before, $this->projectTree(), "The second run changed the tree.\n" . $output);
    }

    public function testUpdateFromTheReleaseKeepsUserFoldersPoolAndProfileAndAddsAllThirtyThreeSkills(): void
    {
        $this->writePoolAndProfile();
        LegacyFixture::archiveRelease($this->repoRoot, 'v' . self::RELEASE, self::RELEASE, $this->artifactDir);
        LegacyFixture::archiveHead($this->repoRoot, self::CANDIDATE, $this->artifactDir);

        $this->writeConsumer(self::RELEASE, ['bundled-skills' => true]);
        ComposerFixture::runComposer($this->project, 'install');
        self::assertFileExists($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        $this->assertPoolAndProfileUntouched();

        $userFolders = $this->writeUserSkillFolders();
        $userBefore = $this->userFolderTree($userFolders);

        // Run 1: `composer update` is still driven by the old plugin code.
        $this->writeConsumer(self::CANDIDATE, ['bundled-skills' => true]);
        ComposerFixture::runComposer($this->project, 'update jardis/dev-skills');
        $this->assertPoolAndProfileUntouched();
        self::assertSame($userBefore, $this->userFolderTree($userFolders), 'User folders after run 1.');

        // Run 2: the candidate plugin migrates.
        $output = ComposerFixture::runComposer($this->project, 'install');

        self::assertStringContainsString('18 bundle skills were renamed', $output);
        $this->assertPoolAndProfileUntouched();
        self::assertSame($userBefore, $this->userFolderTree($userFolders), 'User folders after run 2.');

        $names = $this->bundleNames();
        self::assertCount(33, $names);
        $old = array_keys(RenamedSkills::MAPPING);
        self::assertCount(18, $old);
        self::assertSame(
            $this->sorted([...$names, ...$old, self::VENDOR_SKILL, 'do-mine', 'rules-mine', 'git-foo']),
            $this->folderNames('.claude/skills'),
        );
        self::assertSame(
            $this->sorted([...$names, self::VENDOR_SKILL, 'do-mine', 'git-foo']),
            $this->folderNames('.agents/skills'),
        );
        foreach (RenamedSkills::MAPPING as $oldName => $newName) {
            self::assertStringContainsString(
                sprintf("name: %s\ndescription: Renamed to %s. Load %s instead.\n", $oldName, $newName, $newName),
                (string) file_get_contents($this->project->path('.claude/skills/' . $oldName . '/SKILL.md')),
            );
        }
    }

    public function testRemoveAfterTheFullInstallLeavesOnlyTheUserFilesByteIdentical(): void
    {
        $this->writePoolAndProfile();
        $this->project->writeFile('AGENTS.md', "# my agents notes\n");
        $this->project->writeFile('CLAUDE.md', "# my claude notes\n");
        $this->project->writeFile('.gemini/settings.json', "{\n  \"theme\": \"dark\"\n}\n");
        $userFolders = $this->writeUserSkillFolders();
        $this->writeConsumer(self::CANDIDATE, []);
        LegacyFixture::archiveHead($this->repoRoot, self::CANDIDATE, $this->artifactDir);
        $before = $this->userTree();

        ComposerFixture::runComposer($this->project, 'install');
        self::assertFileExists($this->project->path(Manifest::FILE), 'Precondition: the full install ran.');
        self::assertNotSame($before, $this->userTree(), 'Precondition: the install changed the project.');

        $output = ComposerFixture::runComposer($this->project, 'remove jardis/dev-skills');

        // The documented safety copy of the user's own AGENTS.md stays (README, uninstall section).
        self::assertSame("# my agents notes\n", file_get_contents($this->project->path('AGENTS.md.backup')));
        $expected = $before + ['AGENTS.md.backup' => (string) hash_file('sha256', $this->project->path('AGENTS.md'))];
        ksort($expected);
        self::assertSame(
            $expected,
            $this->withoutEmptySkillRoots($this->userTree(), $before),
            "Remove must restore the user tree exactly.\n" . $output,
        );
        self::assertSame("# my agents notes\n", file_get_contents($this->project->path('AGENTS.md')));
        self::assertSame("# my claude notes\n", file_get_contents($this->project->path('CLAUDE.md')));
        self::assertSame("{\n  \"theme\": \"dark\"\n}\n", file_get_contents($this->project->path('.gemini/settings.json')));
        $this->assertNoManagedFileLeft();
        $this->assertPoolAndProfileUntouched();
        foreach ($userFolders as $folder) {
            self::assertFileExists($this->project->path($folder . '/SKILL.md'));
        }
    }

    public function testRemoveOnAProjectWithoutUserFilesLeavesNoManagedFile(): void
    {
        $this->writePoolAndProfile();
        $this->writeConsumer(self::CANDIDATE, []);
        LegacyFixture::archiveHead($this->repoRoot, self::CANDIDATE, $this->artifactDir);
        $before = $this->userTree();
        ComposerFixture::runComposer($this->project, 'install');

        $output = ComposerFixture::runComposer($this->project, 'remove jardis/dev-skills');

        self::assertSame(
            $before,
            $this->withoutEmptySkillRoots($this->userTree(), $before),
            "Remove must leave only the pool and the profile.\n" . $output,
        );
        foreach (['AGENTS.md', 'CLAUDE.md', '.gemini'] as $created) {
            self::assertFileDoesNotExist($this->project->path($created), $created . ' was created by the plugin and must go.');
        }
        $this->assertNoManagedFileLeft();
        $this->assertPoolAndProfileUntouched();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeConsumer(string $pluginVersion, array $settings): void
    {
        LegacyFixture::writeArtifactConsumerComposerJson(
            $this->project,
            $this->artifactDir,
            $this->fakeVendorRoot,
            $pluginVersion,
            $settings,
        );
    }

    private function writePoolAndProfile(): void
    {
        $this->project->writeFile(self::POOL_INDEX, "# Pool index\nARTIFICIAL-POOL-TOKEN-7f3a\n");
        $this->project->writeFile('.claude/wissen/page.md', "# Page\nARTIFICIAL-PAGE-TOKEN-91c2\n");
        $this->project->writeFile(self::PROFILE, "# Project profile\nARTIFICIAL-PROFILE-TOKEN-5be8\n");
    }

    private function assertPoolAndProfileUntouched(): void
    {
        self::assertSame("# Pool index\nARTIFICIAL-POOL-TOKEN-7f3a\n", file_get_contents($this->project->path(self::POOL_INDEX)));
        self::assertSame("# Page\nARTIFICIAL-PAGE-TOKEN-91c2\n", file_get_contents($this->project->path('.claude/wissen/page.md')));
        self::assertSame("# Project profile\nARTIFICIAL-PROFILE-TOKEN-5be8\n", file_get_contents($this->project->path(self::PROFILE)));
    }

    /**
     * @return list<string> project-relative folders of the user skills
     */
    private function writeUserSkillFolders(): array
    {
        $folders = [
            '.claude/skills/do-mine', '.claude/skills/rules-mine', '.claude/skills/git-foo',
            '.agents/skills/do-mine', '.agents/skills/git-foo',
        ];
        foreach ($folders as $folder) {
            $this->project->writeFile($folder . '/SKILL.md', 'mine: ' . $folder);
        }

        return $folders;
    }

    /**
     * @param list<string> $folders
     * @return array<string, array<string, string>>
     */
    private function userFolderTree(array $folders): array
    {
        $tree = [];
        foreach ($folders as $folder) {
            $tree[$folder] = TreeSnapshot::of($this->project->path($folder));
        }

        return $tree;
    }

    private function assertNoManagedFileLeft(): void
    {
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
        foreach (ShellFormat::cases() as $format) {
            foreach ($this->reviewerRoles() as $role) {
                self::assertFileDoesNotExist($this->project->path($format->pathFor($role)), $format->pathFor($role));
            }
        }
        foreach ($this->bundleNames() as $name) {
            foreach (['.claude/skills', '.agents/skills'] as $root) {
                self::assertDirectoryDoesNotExist($this->project->path($root . '/' . $name), $root . '/' . $name);
            }
        }
        foreach (['.claude/skills', '.agents/skills'] as $root) {
            self::assertDirectoryDoesNotExist($this->project->path($root . '/' . self::VENDOR_SKILL), $root);
        }
        foreach (['AGENTS.md', 'CLAUDE.md'] as $file) {
            if (is_file($this->project->path($file))) {
                $content = (string) file_get_contents($this->project->path($file));
                self::assertStringNotContainsString('jardis/dev-skills', $content, $file);
                self::assertStringNotContainsString(BuildClaudeMdContent::IMPORT_LINE, $content, $file);
            }
        }
        if (is_file($this->project->path('.gemini/settings.json'))) {
            self::assertStringNotContainsString('AGENTS.md', (string) file_get_contents($this->project->path('.gemini/settings.json')));
        }
    }

    /**
     * The project tree without what Composer and the harness own.
     *
     * @return array<string, string>
     */
    private function projectTree(): array
    {
        return array_filter(
            TreeSnapshot::of($this->project->root),
            static function (string $path): bool {
                foreach (['vendor', '.composer-home', 'artifacts'] as $skip) {
                    if ($path === $skip || str_starts_with($path, $skip . '/')) {
                        return false;
                    }
                }

                return true;
            },
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The project tree without Composer's own files: what the user owns.
     *
     * @return array<string, string>
     */
    private function userTree(): array
    {
        return array_diff_key($this->projectTree(), ['composer.json' => true, 'composer.lock' => true]);
    }

    /**
     * Drops the skill root folders (`.claude/skills`, `.agents`) the install created and the removal left behind holding
     * nothing but empty folders; a root that still holds any file stays in the tree and fails the comparison.
     *
     * @param array<string, string> $tree
     * @param array<string, string> $before
     * @return array<string, string>
     */
    private function withoutEmptySkillRoots(array $tree, array $before): array
    {
        foreach (['.claude/skills', '.agents'] as $root) {
            if (isset($before[$root]) || !isset($tree[$root])) {
                continue;
            }
            $inside = TreeSnapshot::of($this->project->path($root));
            if (array_unique(array_values($inside)) === [] || array_unique(array_values($inside)) === ['dir']) {
                unset($tree[$root]);
                foreach (array_keys($inside) as $relative) {
                    unset($tree[$root . '/' . $relative]);
                }
            }
        }

        return $tree;
    }

    /**
     * @return list<string> skill folders of this repo's bundle (a folder without SKILL.md is none)
     */
    private function bundleNames(): array
    {
        $names = [];
        foreach (glob($this->repoRoot . '/skills/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir . '/SKILL.md')) {
                $names[] = basename($dir);
            }
        }

        return $this->sorted($names);
    }

    /**
     * @return list<string>
     */
    private function reviewerRoles(): array
    {
        $roles = array_map(
            static fn (string $file): string => basename($file, '.md'),
            glob($this->repoRoot . '/skills/process-review-board/reviewers/*.md') ?: [],
        );

        return $this->sorted($roles);
    }

    /**
     * @return list<string>
     */
    private function folderNames(string $root): array
    {
        return $this->sorted(array_map(
            'basename',
            glob($this->project->path($root) . '/*', GLOB_ONLYDIR) ?: [],
        ));
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names, SORT_STRING);

        return array_values($names);
    }
}
