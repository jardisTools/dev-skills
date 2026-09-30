<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\SkillUninstaller;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Update from 1.3.x (old bundle names, no manifest) against the real bundle of this repo:
 * redirect skills for the old names, alias globs, the fixed list of old names as the managed
 * set, and the update x config matrix.
 */
final class SkillInstallerRedirectTest extends TestCase
{
    private TempProject $project;
    private string $pluginRoot;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-redirect-project-');
        $this->pluginRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testUpdateFrom136WithAllEighteenInstalledYieldsRedirectsPlusNewSkills(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);

        $report = $this->install(PluginConfig::all());

        $old = array_keys(RenamedSkills::MAPPING);
        self::assertCount(18, $old);
        self::assertEqualsCanonicalizing($old, $report->redirectedSkills());
        foreach (RenamedSkills::MAPPING as $oldName => $newName) {
            self::assertStringContainsString(
                sprintf("name: %s\ndescription: Renamed to %s. Load %s instead.\n", $oldName, $newName, $newName),
                (string) file_get_contents($this->project->path('.claude/skills/' . $oldName . '/SKILL.md')),
            );
            foreach (['.claude/skills', '.agents/skills'] as $root) {
                self::assertFileExists($this->project->path($root . '/' . $newName . '/SKILL.md'));
            }
            self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/' . $oldName));
        }
        // Skills added to the bundle after the rename are installed as well, so the installed set
        // is checked for containing every renamed target and no retired name, not for equalling them.
        self::assertSame([], array_values(array_diff(array_values(RenamedSkills::MAPPING), $report->installedSkills())));
        self::assertSame([], array_values(array_intersect($old, $report->installedSkills())));
    }

    public function testFreshProjectGetsNoRedirect(): void
    {
        $report = $this->install(PluginConfig::all());

        self::assertSame([], $report->redirectedSkills());
        self::assertSame([], $report->notices());
        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/' . $oldName));
            self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/' . $oldName));
        }
    }

    public function testRedirectsAreNeitherInstalledSkillsNorPartOfTheAgentsBlock(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);

        $report = $this->install(PluginConfig::all());

        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertNotContains($oldName, $report->installedSkills());
        }
        $agentsMd = (string) file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('packages-find-existing', $agentsMd);
        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertStringNotContainsString($oldName, $agentsMd);
        }
    }

    public function testRedirectsAreRecordedInTheManifestForClaudeOnlyAndStayStable(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        $this->install(PluginConfig::all());
        $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest;
        self::assertNotNull($manifest);
        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertArrayHasKey('.claude/skills/' . $oldName, $manifest->entries);
            self::assertArrayNotHasKey('.agents/skills/' . $oldName, $manifest->entries);
        }

        $before = TreeSnapshot::ofProject($this->project);
        $second = $this->install(PluginConfig::all());

        self::assertSame($before, TreeSnapshot::ofProject($this->project));
        self::assertSame([], $second->backedUpSkills());
        self::assertCount(18, $second->redirectedSkills());
    }

    public function testMigrationHintIsAReportNoticeNamingTheRenamedSkills(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture', 'do-git-branch']);

        $report = $this->install(PluginConfig::all());

        self::assertCount(1, $report->notices());
        self::assertStringContainsString('2 bundle skills were renamed', $report->notices()[0]);
        self::assertStringContainsString('rules-architecture -> foundation-architecture', $report->notices()[0]);
        self::assertStringContainsString('do-git-branch -> git-start-branch', $report->notices()[0]);
    }

    public function testMatrixAbsentKeyInstallsAllBundleSkillsAndAllRedirects(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer']);

        $report = $this->install((new ReadPluginConfig())([]));

        self::assertEqualsCanonicalizing($this->bundleNames(), $report->installedSkills());
        self::assertEqualsCanonicalizing(
            ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer'],
            $report->redirectedSkills(),
        );
    }

    public function testMatrixFalseWithoutManifestInstallsNoBundleSkillAndNoRedirectAndBacksUpTheOldFolders(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);

        $report = $this->install((new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => false]]));

        self::assertSame([], $report->redirectedSkills());
        self::assertSame([], $report->notices());
        self::assertEqualsCanonicalizing($this->mandatoryNames(), $report->installedSkills());
        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/' . $oldName));
            // No manifest, so no proof that the folder is unchanged: saved once before it goes.
            self::assertFileExists($this->project->path('.claude/.jardis-backup/' . $oldName . '/SKILL.md'));
        }
        self::assertEqualsCanonicalizing(array_keys(RenamedSkills::MAPPING), $report->removedBundledSkills());
    }

    public function testMatrixFalseAfterTheMigrationRemovesUnchangedRedirectsAndBacksUpChangedOnes(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        $this->install(PluginConfig::all());
        $this->project->writeFile('.claude/skills/rules-testing/SKILL.md', "edited by the user\n");

        $report = $this->install((new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => false]]));

        self::assertSame([], $report->redirectedSkills());
        self::assertEqualsCanonicalizing($this->mandatoryNames(), $report->installedSkills());
        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/' . $oldName));
        }
        // Only the edited redirect is saved; the migration run had saved the stubs under the plain names.
        self::assertSame(['rules-testing'], array_column($report->backedUpSkills(), 'skill'));
        $saved = glob($this->project->path('.claude/.jardis-backup/rules-testing-*/SKILL.md')) ?: [];
        self::assertCount(1, $saved);
        self::assertSame("edited by the user\n", file_get_contents($saved[0]));
    }

    public function testMatrixRulesGlobInstallsOnlyFoundationSkillsAndTheFourRulesRedirects(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);

        $report = $this->install((new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => ['rules-*']]]));

        self::assertEqualsCanonicalizing($this->mandatoryNames(), $report->installedSkills());
        self::assertEqualsCanonicalizing(
            ['rules-architecture', 'rules-patterns', 'rules-testing', 'rules-frontend'],
            $report->redirectedSkills(),
        );
        foreach (['do-git-branch', 'platform-usage', 'jardis-catalog', 'schema-authoring'] as $gone) {
            self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/' . $gone));
        }
        foreach (['.claude/skills', '.agents/skills'] as $root) {
            self::assertDirectoryDoesNotExist($this->project->path($root . '/git-start-branch'));
            self::assertDirectoryDoesNotExist($this->project->path($root . '/generated-code-extend'));
        }
    }

    public function testMatrixGitGlobInstallsTheGitSkillsItReachesPlusFoundation(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);

        $report = $this->install((new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => ['do-git-*']]]));

        $expected = [
            ...$this->mandatoryNames(),
            'git-start-branch',
            'git-commit-change',
            'git-push-and-open-pr',
            'git-check-compliance',
        ];
        self::assertEqualsCanonicalizing($expected, $report->installedSkills());
    }

    public function testMatrixLocallyChangedOldFolderIsBackedUpBeforeTheRedirectReplacesIt(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        $this->project->writeFile('.claude/skills/platform-usage/SKILL.md', "my own notes\n");

        $report = $this->install(PluginConfig::all());

        self::assertContains('platform-usage', array_column($report->backedUpSkills(), 'skill'));
        self::assertSame("my own notes\n", file_get_contents($this->project->path('.claude/.jardis-backup/platform-usage/SKILL.md')));
        self::assertStringContainsString(
            'Renamed to generated-code-wire-transport.',
            (string) file_get_contents($this->project->path('.claude/skills/platform-usage/SKILL.md')),
        );
    }

    public function testMatrixChangedRedirectOfAHealthyManifestIsBackedUpBeforeItIsReplaced(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        $this->install(PluginConfig::all());
        $this->project->writeFile('.claude/skills/platform-usage/SKILL.md', "my own notes\n");

        $report = $this->install(PluginConfig::all());

        self::assertSame(['platform-usage'], array_column($report->backedUpSkills(), 'skill'));
        self::assertStringContainsString(
            'Renamed to generated-code-wire-transport.',
            (string) file_get_contents($this->project->path('.claude/skills/platform-usage/SKILL.md')),
        );
    }

    public function testWithoutManifestOnlyTheFixedListOfOldNamesIsManaged(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture']);
        $mine = ['git-foo', 'design-mine', 'do-mine', 'rules-mine', 'foundation-mine', 'platform-mine'];
        foreach ($mine as $name) {
            $this->project->writeFile('.claude/skills/' . $name . '/SKILL.md', $name);
        }
        $before = [];
        foreach ($mine as $name) {
            $before[$name] = TreeSnapshot::of($this->project->path('.claude/skills/' . $name));
        }

        foreach ([PluginConfig::all(), PluginConfig::onlyMandatory()] as $config) {
            $this->install($config);

            foreach ($mine as $name) {
                self::assertSame($before[$name], TreeSnapshot::of($this->project->path('.claude/skills/' . $name)), $name);
                self::assertDirectoryDoesNotExist($this->project->path('.claude/.jardis-backup/' . $name));
            }
        }
    }

    public function testDefectiveManifestOverOldFoldersCreatesNoRedirectAndDeletesNothing(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        $this->project->writeFile(Manifest::FILE, '{not json');
        $before = TreeSnapshot::of($this->project->path('.claude/skills'));

        $report = $this->install(PluginConfig::all());

        self::assertSame([], $report->redirectedSkills());
        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            self::assertSame(
                $before[$oldName . '/SKILL.md'],
                hash_file('sha256', $this->project->path('.claude/skills/' . $oldName . '/SKILL.md')),
            );
        }
    }

    public function testUninstallAfterTheMigrationRemovesTheRedirectsAndLeavesUserFolders(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        $this->project->writeFile('.claude/skills/git-foo/SKILL.md', 'mine');
        $this->project->writeFile('.claude/skills/design-mine/SKILL.md', 'mine');
        $this->install(PluginConfig::all());

        (new SkillUninstaller())($this->project->root);

        self::assertSame(
            ['design-mine', 'git-foo'],
            array_map('basename', glob($this->project->path('.claude/skills') . '/*') ?: []),
        );
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/foundation-architecture'));
    }

    private function install(PluginConfig $config): InstallReport
    {
        $installer = new SkillInstaller(config: $config, pluginRoot: $this->pluginRoot);

        return $installer($this->project->root, $this->project->path('vendor'));
    }

    /**
     * @return list<string> every skill folder of this repo's bundle
     */
    private function bundleNames(): array
    {
        return array_map('basename', glob($this->pluginRoot . '/skills/*', GLOB_ONLYDIR) ?: []);
    }

    /**
     * @return list<string> the bundle skills a `false` config still installs (mandatory groups)
     */
    private function mandatoryNames(): array
    {
        return array_values(array_filter(
            $this->bundleNames(),
            static fn (string $name): bool => str_starts_with($name, 'foundation-') || str_starts_with($name, 'process-'),
        ));
    }
}
