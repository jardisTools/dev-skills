<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Handler\Uninstall\RemoveManagedPaths;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

final class RemoveManagedPathsTest extends TestCase
{
    private const SUM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testHealthyManifestRemovesExactlyItsPathsInBothFoldersAndTheManifest(): void
    {
        foreach (['.claude/skills/adapter-cache', '.agents/skills/adapter-cache', '.claude/skills/process-alpha',
            '.agents/skills/process-alpha'] as $folder) {
            $this->project->writeFile($folder . '/SKILL.md', 'managed');
        }
        // Same prefix as a managed skill, but not in the manifest: the user's.
        $this->project->writeFile('.claude/skills/adapter-mine/SKILL.md', 'mine');
        foreach (['do-mine', 'rules-mine', 'git-foo'] as $name) {
            $this->project->writeFile('.claude/skills/' . $name . '/SKILL.md', $name);
            $this->project->writeFile('.agents/skills/' . $name . '/SKILL.md', $name);
        }
        $this->project->writeFile('.claude/.jardis-backup/adapter-cache/SKILL.md', 'backup');
        $this->project->writeFile(Manifest::FILE, '{}');
        $userBefore = TreeSnapshot::of($this->project->path('.claude/skills/adapter-mine'));

        $removed = $this->remove($this->healthy([
            '.claude/skills/adapter-cache', '.agents/skills/adapter-cache',
            '.claude/skills/process-alpha', '.agents/skills/process-alpha',
        ]));

        self::assertSame(['adapter-cache', 'process-alpha'], $removed);
        foreach (['.claude', '.agents'] as $root) {
            self::assertDirectoryDoesNotExist($this->project->path($root . '/skills/adapter-cache'));
            self::assertDirectoryDoesNotExist($this->project->path($root . '/skills/process-alpha'));
            foreach (['do-mine', 'rules-mine', 'git-foo'] as $name) {
                self::assertFileExists($this->project->path($root . '/skills/' . $name . '/SKILL.md'));
            }
        }
        self::assertSame($userBefore, TreeSnapshot::of($this->project->path('.claude/skills/adapter-mine')));
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
        self::assertFileExists($this->project->path('.claude/.jardis-backup/adapter-cache/SKILL.md'));
    }

    public function testManifestKeysOutsideSkillFoldersOrClimbingOutAreIgnored(): void
    {
        $this->project->writeFile('docs/keep/SKILL.md', 'docs');
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'managed');
        $this->project->writeFile('outside/skills/victim/SKILL.md', 'victim');

        $removed = $this->remove($this->healthy([
            'docs/keep',
            '.claude/skills/../../docs/keep',
            '.claude/skills/adapter-cache',
        ]));

        self::assertSame(['adapter-cache'], $removed);
        self::assertFileExists($this->project->path('docs/keep/SKILL.md'));
        self::assertFileExists($this->project->path('outside/skills/victim/SKILL.md'));
    }

    public function testWithoutManifestTheFixedOldNamesAndVendorPrefixesGoAndNothingElse(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        foreach (['adapter-cache', 'core-kernel', 'support-data', 'tools-builder-engine'] as $vendorSkill) {
            $this->project->writeFile('.claude/skills/' . $vendorSkill . '/SKILL.md', 'vendor');
        }
        $mine = ['do-mine', 'rules-mine', 'git-foo', 'platform-mine', 'plan-mine', 'schema-mine', 'my-local',
            'adapter-cache.backup'];
        foreach ($mine as $name) {
            $this->project->writeFile('.claude/skills/' . $name . '/SKILL.md', $name);
        }
        // 1.3.x never wrote here: not touched without a manifest.
        $this->project->writeFile('.agents/skills/rules-architecture/SKILL.md', 'not ours to judge');
        $this->project->writeFile('.claude/.jardis-backup/x/SKILL.md', 'backup');

        $removed = $this->remove(new ManifestReadResult(ManifestState::Missing));
        sort($removed);

        $expected = [...array_keys(RenamedSkills::MAPPING), 'adapter-cache', 'core-kernel', 'support-data', 'tools-builder-engine'];
        sort($expected);
        self::assertSame($expected, $removed);
        $left = array_map('basename', glob($this->project->path('.claude/skills') . '/*') ?: []);
        sort($left);
        $mineSorted = $mine;
        sort($mineSorted);
        self::assertSame($mineSorted, $left);
        self::assertFileExists($this->project->path('.agents/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/.jardis-backup/x/SKILL.md'));
    }

    public function testDefectiveOrTooNewManifestRemovesNothing(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture']);
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'vendor');
        $before = TreeSnapshot::of($this->project->path('.claude'));

        self::assertSame([], $this->remove(new ManifestReadResult(ManifestState::Defective, null, 'x')));
        self::assertSame([], $this->remove(new ManifestReadResult(ManifestState::TooNew, new Manifest(2, '9.9.9'), 'y')));

        self::assertSame($before, TreeSnapshot::of($this->project->path('.claude')));
    }

    public function testReturnsEmptyWhenNothingIsInstalled(): void
    {
        self::assertSame([], $this->remove(new ManifestReadResult(ManifestState::Missing)));
        self::assertSame([], $this->remove($this->healthy([])));
    }

    public function testOldNamesAreTheEighteenBundleNamesOfRelease1x(): void
    {
        $old = array_keys(RenamedSkills::MAPPING);
        $expected = LegacyFixture::BUNDLE_NAMES;
        sort($old);
        sort($expected);

        self::assertSame($expected, $old);
        self::assertCount(18, array_unique(RenamedSkills::MAPPING));
    }

    /**
     * @param list<string> $paths
     */
    private function healthy(array $paths): ManifestReadResult
    {
        $entries = [];
        foreach ($paths as $path) {
            $entries[$path] = ['source' => 'bundle', 'sha256' => self::SUM];
        }

        return new ManifestReadResult(ManifestState::Healthy, new Manifest(Manifest::SCHEMA_VERSION, '1.0.0', $entries));
    }

    /**
     * @return list<string>
     */
    private function remove(ManifestReadResult $manifest): array
    {
        return (new RemoveManagedPaths(new Filesystem()))($this->project->root, $manifest);
    }
}
