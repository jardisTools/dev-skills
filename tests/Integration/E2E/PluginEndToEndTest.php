<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\E2E;

use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Tests\Support\ComposerFixture;
use JardisTools\DevSkills\Handler\Manifest\ChecksumDirectory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests that spin up a real Composer project, require this plugin
 * via a path repository, run real `composer install` and `composer remove`,
 * and assert the resulting filesystem state.
 *
 * Mock-based tests in PluginTest cover behaviour at the unit boundary; these
 * tests verify the contract Composer enforces in practice.
 */
final class PluginEndToEndTest extends TestCase
{
    private TempProject $project;
    private string $pluginRoot;
    private string $fakeVendorRoot;

    protected function setUp(): void
    {
        $this->project        = new TempProject('dev-skills-e2e-');
        $this->pluginRoot     = (string) realpath(__DIR__ . '/../../..');
        $this->fakeVendorRoot = (string) realpath(__DIR__ . '/../../Fixture/E2E/fake-vendor/jardisadapter-fakecache');

        if (!is_dir($this->pluginRoot . '/src')) {
            self::fail('Plugin root not resolvable: ' . $this->pluginRoot);
        }
        if (!is_dir($this->fakeVendorRoot . '/.claude')) {
            self::fail('Fake vendor fixture not resolvable: ' . $this->fakeVendorRoot);
        }
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testComposerInstallCopiesBundledAndVendorSkillsAndAggregatesAgentsMd(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        $this->runComposer('install');

        // Vendor skill from the fake adapter package was discovered + copied.
        self::assertFileExists(
            $this->project->path('.claude/skills/adapter-fakecache/SKILL.md'),
            'Vendor skill was not copied by the plugin during composer install.',
        );

        // Second target: identical content under .agents/skills.
        self::assertFileEquals(
            $this->project->path('.claude/skills/adapter-fakecache/SKILL.md'),
            $this->project->path('.agents/skills/adapter-fakecache/SKILL.md'),
            'Vendor skill was not mirrored into .agents/skills.',
        );

        // Plugin-own bundled skills were copied because bundled-skills: true.
        self::assertFileExists(
            $this->project->path('.claude/skills/foundation-architecture/SKILL.md'),
            'Bundled skill foundation-architecture was not copied.',
        );
        self::assertFileExists(
            $this->project->path('.claude/skills/generated-code-extend/SKILL.md'),
            'Bundled skill generated-code-extend was not copied.',
        );
        self::assertFileEquals(
            $this->project->path('.claude/skills/foundation-architecture/SKILL.md'),
            $this->project->path('.agents/skills/foundation-architecture/SKILL.md'),
            'Bundled skill was not mirrored into .agents/skills.',
        );

        // Manifest written; no `*.backup` in either skill folder.
        self::assertFileExists($this->project->path('.claude/skills/.jardis-managed.json'));
        self::assertSame([], glob($this->project->path('.claude/skills') . '/*.backup*') ?: []);
        self::assertSame([], glob($this->project->path('.agents/skills') . '/*.backup*') ?: []);

        // AGENTS.md aggregation contains the fake vendor's body marker.
        $agentsMd = (string) file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString(
            'BEGIN jardis/dev-skills',
            $agentsMd,
            'AGENTS.md is missing the managed block header.',
        );
        self::assertStringContainsString(
            'FAKE_VENDOR_AGENTS_BODY_MARKER',
            $agentsMd,
            'AGENTS.md did not aggregate the fake vendor body.',
        );
    }

    public function testComposerInstallWithoutBundledSkillsConfigInstallsAllSkills(): void
    {
        // No bundled-skills key in extra -> every bundle skill plus the vendor skills, in both folders.
        $this->writeConsumerComposerJson(bundledSkills: false);
        $this->runComposer('install');

        self::assertFileExists(
            $this->project->path('.claude/skills/adapter-fakecache/SKILL.md'),
            'Vendor skill must be installed even without explicit bundled-skills config.',
        );
        foreach (['packages-find-existing', 'start-orientation', 'design-headless-mcp', 'foundation-architecture', 'generated-code-extend'] as $name) {
            self::assertFileExists(
                $this->project->path('.claude/skills/' . $name . '/SKILL.md'),
                $name . ' must be installed when the bundled-skills key is absent.',
            );
            self::assertFileEquals(
                $this->project->path('.claude/skills/' . $name . '/SKILL.md'),
                $this->project->path('.agents/skills/' . $name . '/SKILL.md'),
            );
        }
    }

    public function testFreshInstallCreatesNoRedirectSkills(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        $output = $this->runComposer('install');

        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/' . $oldName));
            self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/' . $oldName));
        }
        self::assertStringNotContainsString('were renamed', $output);
    }

    public function testComposerRemovePluginCleansUpJardisSkillsAndAgentsMd(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        $this->runComposer('install');

        self::assertFileExists($this->project->path('.claude/skills/adapter-fakecache/SKILL.md'));
        self::assertFileExists($this->project->path('AGENTS.md'));

        $output = $this->runComposer('remove jardis/dev-skills');

        $remaining = is_file($this->project->path('AGENTS.md'))
            ? (string) file_get_contents($this->project->path('AGENTS.md'))
            : '<file deleted>';

        self::assertDirectoryDoesNotExist(
            $this->project->path('.claude/skills/adapter-fakecache'),
            "Vendor skill should be cleaned up on plugin removal (managed prefix).\nComposer output:\n" . $output,
        );
        self::assertDirectoryDoesNotExist(
            $this->project->path('.claude/skills/foundation-architecture'),
            "Bundled skill should be cleaned up on plugin removal.\nComposer output:\n" . $output,
        );
        self::assertFileDoesNotExist(
            $this->project->path('AGENTS.md'),
            "AGENTS.md containing only the managed block should be deleted on plugin removal.\nRemaining AGENTS.md content:\n" . $remaining . "\n---\nComposer output:\n" . $output,
        );
    }

    public function testComposerRemoveDeletesExactlyTheManifestPathsInBothFoldersAndKeepsUserContent(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        $this->runComposer('install');

        $userFolders = ['.claude/skills/do-mine', '.claude/skills/rules-mine', '.claude/skills/git-foo',
            '.claude/skills/design-mine', '.agents/skills/do-mine', '.agents/skills/git-foo'];
        foreach ($userFolders as $folder) {
            $this->project->writeFile($folder . '/SKILL.md', 'mine: ' . $folder);
        }
        $this->project->writeFile('.claude/.jardis-backup/adapter-fakecache/SKILL.md', 'kept backup');
        $checksum = new ChecksumDirectory();
        $userBefore = array_map(fn (string $f): string => $checksum($this->project->path($f)), $userFolders);

        $manifest = json_decode((string) file_get_contents($this->project->path('.claude/skills/.jardis-managed.json')), true);
        $managedPaths = array_keys($manifest['paths']);
        self::assertContains('.claude/skills/adapter-fakecache', $managedPaths);
        self::assertContains('.agents/skills/foundation-architecture', $managedPaths);
        foreach ($managedPaths as $path) {
            self::assertDirectoryExists($this->project->path($path));
        }

        $this->runComposer('remove jardis/dev-skills');

        foreach ($managedPaths as $path) {
            self::assertDirectoryDoesNotExist($this->project->path($path), $path . ' must be removed.');
        }
        self::assertFileDoesNotExist($this->project->path('.claude/skills/.jardis-managed.json'));
        self::assertSame(
            array_map(fn (string $f): string => $checksum($this->project->path($f)), $userFolders),
            $userBefore,
            'User skill folders must stay byte-identical.',
        );
        self::assertSame(
            ['design-mine', 'do-mine', 'git-foo', 'rules-mine'],
            array_map('basename', glob($this->project->path('.claude/skills') . '/*') ?: []),
        );
        self::assertSame(
            ['do-mine', 'git-foo'],
            array_map('basename', glob($this->project->path('.agents/skills') . '/*') ?: []),
        );
        self::assertSame('kept backup', file_get_contents($this->project->path('.claude/.jardis-backup/adapter-fakecache/SKILL.md')));
    }

    public function testNoDevInstallDeletesNothing(): void
    {
        ComposerFixture::writeConsumerComposerJson(
            $this->project,
            $this->pluginRoot,
            $this->fakeVendorRoot,
            bundledSkills: true,
            pluginAsDevRequirement: true,
        );
        $this->runComposer('install');
        $before = TreeSnapshot::ofProject($this->project);
        self::assertDirectoryExists($this->project->path('.claude/skills/foundation-architecture'));

        $output = $this->runComposer('install --no-dev');

        self::assertDirectoryDoesNotExist(
            $this->project->path('vendor/jardis/dev-skills'),
            'Precondition: --no-dev really uninstalled the plugin package.',
        );
        self::assertSame($before, TreeSnapshot::ofProject($this->project), "Nothing may change.\n" . $output);
    }

    public function testGlobalContextWritesAndDeletesNothing(): void
    {
        $home = $this->project->mkdir('global-home');
        $workdir = $this->project->mkdir('elsewhere');
        $this->project->writeFile('global-home/composer.json', (string) json_encode([
            'repositories' => [
                ['type' => 'path', 'url' => $this->pluginRoot, 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $this->fakeVendorRoot, 'options' => ['symlink' => false]],
            ],
            'minimum-stability' => 'dev',
            'config' => ['allow-plugins' => ['jardis/dev-skills' => true]],
        ], JSON_UNESCAPED_SLASHES));
        $this->project->writeFile('global-home/.claude/skills/adapter-fakecache/SKILL.md', 'global user data');
        $this->project->writeFile('global-home/AGENTS.md', "# hand written\n");
        $claudeBefore = TreeSnapshot::of($home . '/.claude');

        [$exit, $output] = ComposerFixture::run($workdir, $home, 'global require jardis/dev-skills jardisadapter/fakecache');
        self::assertSame(0, $exit, $output);
        self::assertDirectoryExists($home . '/vendor/jardis/dev-skills', 'Precondition: plugin installed globally.');

        self::assertSame($claudeBefore, TreeSnapshot::of($home . '/.claude'));
        self::assertDirectoryDoesNotExist($home . '/.agents');
        self::assertSame('global user data', file_get_contents($home . '/.claude/skills/adapter-fakecache/SKILL.md'));
        self::assertSame("# hand written\n", file_get_contents($home . '/AGENTS.md'));
        self::assertSame([], TreeSnapshot::of($workdir));

        [$exit, $output] = ComposerFixture::run($workdir, $home, 'global remove jardis/dev-skills');
        self::assertSame(0, $exit, $output);
        self::assertDirectoryDoesNotExist($home . '/vendor/jardis/dev-skills');
        self::assertSame($claudeBefore, TreeSnapshot::of($home . '/.claude'));
        self::assertSame('global user data', file_get_contents($home . '/.claude/skills/adapter-fakecache/SKILL.md'));
        self::assertSame("# hand written\n", file_get_contents($home . '/AGENTS.md'));
    }

    public function testInstallWorksWithoutGitDirectory(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        self::assertDirectoryDoesNotExist($this->project->path('.git'));

        $this->runComposer('install');

        self::assertDirectoryDoesNotExist($this->project->path('.git'));
        self::assertFileExists($this->project->path('.claude/skills/.jardis-managed.json'));
        self::assertFileExists($this->project->path('.claude/skills/foundation-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.agents/skills/adapter-fakecache/SKILL.md'));
        self::assertFileExists($this->project->path('AGENTS.md'));
    }

    public function testInstallWorksWithHomeUnset(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);

        [$exit, $output] = ComposerFixture::run(
            $this->project->root,
            $this->project->path('.composer-home'),
            'install',
            'env -u HOME',
        );

        self::assertSame(0, $exit, $output);
        self::assertFileExists($this->project->path('.claude/skills/.jardis-managed.json'));
        self::assertFileExists($this->project->path('.agents/skills/foundation-architecture/SKILL.md'));
    }

    public function testNoScriptsInstallsLikeNormal(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        $this->runComposer('install');
        $normal = TreeSnapshot::ofProject($this->project);

        $other = new TempProject('dev-skills-e2e-noscripts-');
        try {
            ComposerFixture::writeConsumerComposerJson($other, $this->pluginRoot, $this->fakeVendorRoot, true);
            ComposerFixture::runComposer($other, 'install --no-scripts');

            self::assertSame($normal, TreeSnapshot::ofProject($other));
        } finally {
            $other->cleanup();
        }
    }

    public function testCoreFailureExitsNonZeroAndAfterRemovingTheObstacleTheRerunSucceeds(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        // A directory where the manifest file must go: the core cannot finish.
        $this->project->mkdir('.claude/skills/.jardis-managed.json');

        [$exit, $output] = ComposerFixture::run($this->project->root, $this->project->path('.composer-home'), 'install');
        self::assertNotSame(0, $exit, "Core failure must fail the run.\n" . $output);

        rmdir($this->project->path('.claude/skills/.jardis-managed.json'));
        $this->runComposer('install');

        self::assertFileExists($this->project->path('.claude/skills/.jardis-managed.json'));
    }

    public function testManifestTooNewLeavesEverythingByteIdenticalAndExitsZero(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: true);
        $this->project->writeFile(
            '.claude/skills/.jardis-managed.json',
            '{"schemaVersion":2,"pluginVersion":"9.9.9","paths":{}}' . "\n",
        );
        $this->project->writeFile('.claude/skills/foundation-architecture/SKILL.md', 'old content');
        $before = TreeSnapshot::ofProject($this->project);

        $output = $this->runComposer('install');

        self::assertSame($before, TreeSnapshot::ofProject($this->project));
        self::assertStringContainsString('schema 2', $output);
    }

    private function writeConsumerComposerJson(bool $bundledSkills): void
    {
        ComposerFixture::writeConsumerComposerJson(
            $this->project,
            $this->pluginRoot,
            $this->fakeVendorRoot,
            $bundledSkills,
        );
    }

    private function runComposer(string $command): string
    {
        return ComposerFixture::runComposer($this->project, $command);
    }
}
