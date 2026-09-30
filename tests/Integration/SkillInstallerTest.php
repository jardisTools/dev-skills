<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use JardisTools\DevSkills\Handler\Manifest\ChecksumDirectory;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class SkillInstallerTest extends TestCase
{
    private TempProject $project;
    private TempProject $pluginRepo;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-project-');
        $this->pluginRepo = new TempProject('dev-skills-plugin-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->pluginRepo->cleanup();
    }

    public function testInstallsVendorAndPluginSkillsAndAggregatesAgentsMd(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            'cache-skill',
        );
        $this->project->writeFile(
            'vendor/jardisadapter/cache/AGENTS.md',
            "# cache\nCache rules.",
        );
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(2, $report->installedSkillCount());
        self::assertSame(1, $report->agentsFilesAggregated());
        self::assertFileExists($this->project->path('.claude/skills/adapter-cache/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/plan-requirements/SKILL.md'));
        self::assertFileEquals(
            $this->project->path('.claude/skills/adapter-cache/SKILL.md'),
            $this->project->path('.agents/skills/adapter-cache/SKILL.md'),
        );
        self::assertFileEquals(
            $this->project->path('.claude/skills/plan-requirements/SKILL.md'),
            $this->project->path('.agents/skills/plan-requirements/SKILL.md'),
        );
        self::assertStringContainsString(
            'Cache rules.',
            file_get_contents($this->project->path('AGENTS.md')),
        );
    }

    public function testAbsentKeyInstallsAllSkillsIntoAnExistingThreeSkillProject(): void
    {
        // Fixture "1.3.6, key missing": a project that got the three former default skills
        // from 1.3.x, config without a bundled-skills key -> every bundle skill is installed.
        foreach (['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer', 'plan-requirements', 'rules-architecture'] as $name) {
            $this->pluginRepo->writeFile('skills/' . $name . '/SKILL.md', $name);
        }
        LegacyFixture::writeInstalledBundle($this->project, ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer']);

        $installer = new SkillInstaller(
            config: (new ReadPluginConfig())([]),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(5, $report->installedSkillCount());
        foreach (['.claude/skills', '.agents/skills'] as $root) {
            foreach (['jardis-catalog', 'plan-requirements', 'rules-architecture'] as $name) {
                self::assertFileExists($this->project->path($root . '/' . $name . '/SKILL.md'));
            }
        }
    }

    public function testFalseKeepsOnlyMandatoryGroupsAndRemovesTheRestViaManifest(): void
    {
        foreach (['foundation-alpha', 'process-beta', 'rules-architecture'] as $name) {
            $this->pluginRepo->writeFile('skills/' . $name . '/SKILL.md', $name);
        }
        $this->installer(PluginConfig::all());
        self::assertFileExists($this->project->path('.agents/skills/rules-architecture/SKILL.md'));

        $report = $this->installer((new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => false]]));

        self::assertSame(['foundation-alpha', 'process-beta'], $report->installedSkills());
        self::assertSame(['rules-architecture'], $report->removedBundledSkills());
        self::assertSame([], $report->backedUpSkills());
        foreach (['.claude/skills', '.agents/skills'] as $root) {
            self::assertDirectoryDoesNotExist($this->project->path($root . '/rules-architecture'));
            self::assertFileExists($this->project->path($root . '/foundation-alpha/SKILL.md'));
            self::assertFileExists($this->project->path($root . '/process-beta/SKILL.md'));
        }
        $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest;
        self::assertNotNull($manifest);
        self::assertSame(
            ['.agents/skills/foundation-alpha', '.agents/skills/process-beta', '.claude/skills/foundation-alpha', '.claude/skills/process-beta'],
            array_keys($manifest->entries),
        );
    }

    public function testFalseIsRespectedOnEveryFurtherRun(): void
    {
        $this->pluginRepo->writeFile('skills/foundation-alpha/SKILL.md', 'a');
        $this->pluginRepo->writeFile('skills/rules-architecture/SKILL.md', 'r');
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => false]]);

        $this->installer($config);
        $report = $this->installer($config);

        self::assertSame(['foundation-alpha'], $report->installedSkills());
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-architecture'));
    }

    public function testFilteredConfigInstallsSubsetPlusMandatoryAndRemovesStale(): void
    {
        foreach (['plan-requirements', 'rules-architecture', 'foundation-alpha'] as $name) {
            $this->pluginRepo->writeFile('skills/' . $name . '/SKILL.md', $name);
        }
        $this->installer(PluginConfig::all());

        $report = $this->installer(PluginConfig::filtered(['plan-*'], ['foundation-alpha']));

        self::assertSame(['foundation-alpha', 'plan-requirements'], $report->installedSkills());
        self::assertSame(['rules-architecture'], $report->removedBundledSkills());
        self::assertFileExists($this->project->path('.claude/skills/plan-requirements/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/foundation-alpha/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-architecture'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/rules-architecture'));
        self::assertNotEmpty(array_filter(
            $report->warnings(),
            static fn (string $w): bool => str_contains($w, 'has no effect on "foundation-alpha"'),
        ));
    }

    public function testStaleRemovalBacksUpLocalEditsAndSparesFoldersNotInTheManifest(): void
    {
        $this->pluginRepo->writeFile('skills/rules-architecture/SKILL.md', 'r');
        $this->pluginRepo->writeFile('skills/rules-testing/SKILL.md', 't');
        $this->installer(PluginConfig::all());
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', 'edited locally');
        // Same name as a bundle skill that is not selected, but the plugin never installed it here.
        $this->project->writeFile('.claude/skills/rules-patterns/SKILL.md', 'user folder');
        $this->pluginRepo->writeFile('skills/rules-patterns/SKILL.md', 'p');

        $report = $this->installer(PluginConfig::filtered(['rules-testing'], []));

        self::assertSame(['rules-architecture'], $report->removedBundledSkills());
        self::assertSame(
            'edited locally',
            file_get_contents($this->project->path('.claude/.jardis-backup/rules-architecture/SKILL.md')),
        );
        self::assertCount(1, $report->backedUpSkills());
        self::assertFileExists($this->project->path('.claude/skills/rules-patterns/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/rules-architecture'));
    }

    public function testAbortAfterStagingKeepsDeselectedSkillsUntouched(): void
    {
        $this->pluginRepo->writeFile('skills/rules-architecture/SKILL.md', 'r');
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/beta/SKILL.md', 'beta-v1');
        $this->installer(PluginConfig::all());

        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/beta/SKILL.md', 'beta-v2');
        symlink(
            $this->project->path('vendor/none'),
            $this->project->path('vendor/jardisadapter/cache/.claude/skills/beta/broken'),
        );

        try {
            $this->installer(PluginConfig::onlyMandatory());
            self::fail('The install must abort on the uncopyable entry.');
        } catch (InstallFailedException) {
            // expected
        }

        self::assertFileExists($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.agents/skills/rules-architecture/SKILL.md'));
    }

    public function testWritesManifestForBothTargetsWithChecksumOfTheTargets(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'cache-skill');
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');

        $this->install('1.4.0');

        $read = (new ReadManifest())($this->project->path(Manifest::FILE), '1.4.0');
        self::assertSame(ManifestState::Healthy, $read->state);
        self::assertNotNull($read->manifest);
        self::assertSame('1.4.0', $read->manifest->pluginVersion);
        self::assertSame(
            [
                '.agents/skills/adapter-cache',
                '.agents/skills/plan-requirements',
                '.claude/skills/adapter-cache',
                '.claude/skills/plan-requirements',
            ],
            array_keys($read->manifest->entries),
        );
        foreach ($read->manifest->entries as $key => $entry) {
            self::assertSame((new ChecksumDirectory())($this->project->path($key)), $entry['sha256'], $key);
        }
        self::assertSame('jardisadapter/cache', $read->manifest->entries['.agents/skills/adapter-cache']['source']);
        self::assertSame('jardis/dev-skills', $read->manifest->entries['.claude/skills/plan-requirements']['source']);
    }

    public function testLocallyChangedManagedSkillIsBackedUpThenReplaced(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'new');
        $this->install();

        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'edited by user');
        $report = $this->install();

        self::assertCount(1, $report->backedUpSkills());
        self::assertSame(
            $this->project->path('.claude/.jardis-backup/adapter-cache'),
            $report->backedUpSkills()[0]['backupPath'],
        );
        self::assertSame(
            'edited by user',
            file_get_contents($this->project->path('.claude/.jardis-backup/adapter-cache/SKILL.md')),
        );
        self::assertSame('new', file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')));
        self::assertSame('new', file_get_contents($this->project->path('.agents/skills/adapter-cache/SKILL.md')));
    }

    public function testRepeatedLocalChangeKeepsOlderBackupsUnderTimestampSuffix(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'new');
        $this->install();

        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'edit one');
        $this->install();
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'edit two');
        $this->install();

        $backups = array_map('basename', glob($this->project->path('.claude/.jardis-backup/*')) ?: []);
        sort($backups);
        self::assertCount(2, $backups);
        self::assertSame('adapter-cache', $backups[0]);
        self::assertMatchesRegularExpression('/^adapter-cache-\d{8}T\d{6}(-\d+)?$/', $backups[1]);
        self::assertSame(
            'edit one',
            file_get_contents($this->project->path('.claude/.jardis-backup/adapter-cache/SKILL.md')),
        );
    }

    public function testUnchangedInstallsCreateNoBackupAndNoBackupSiblings(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'new');
        $this->install();
        $report = $this->install();
        $report = $this->install();

        self::assertSame([], $report->backedUpSkills());
        self::assertDirectoryDoesNotExist($this->project->path('.claude/.jardis-backup'));
        self::assertSame([], $this->backupSiblings());
    }

    public function testWithoutManifestLegacyBundleFolderIsBackedUpOnce(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture', 'schema-authoring']);
        $this->pluginRepo->writeFile('skills/rules-architecture/SKILL.md', 'new-rules');
        $this->pluginRepo->writeFile('skills/schema-authoring/SKILL.md', 'new-schema');

        $first = $this->install();
        $second = $this->install();

        self::assertCount(2, $first->backedUpSkills());
        self::assertSame(
            LegacyFixture::stubContent('rules-architecture'),
            file_get_contents($this->project->path('.claude/.jardis-backup/rules-architecture/SKILL.md')),
        );
        self::assertSame('new-rules', file_get_contents($this->project->path('.claude/skills/rules-architecture/SKILL.md')));
        self::assertSame([], $second->backedUpSkills());
        self::assertSame([], $this->backupSiblings());
    }

    public function testUserFolderOfSameNameNotInManifestIsBackedUpWithReportEntry(): void
    {
        $this->pluginRepo->writeFile('skills/process-concept/SKILL.md', 'bundle-content');
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan');
        $this->install();
        // A manifest exists now; the user later adds a same-named folder for a new bundle skill.
        $this->pluginRepo->writeFile('skills/process-verify/SKILL.md', 'verify-bundle');
        $this->project->writeFile('.claude/skills/process-verify/SKILL.md', 'user verify');
        $this->project->writeFile('.agents/skills/process-verify/SKILL.md', 'user agents verify');

        $report = $this->install();

        self::assertCount(2, $report->backedUpSkills());
        self::assertSame('user verify', file_get_contents($this->project->path('.claude/.jardis-backup/process-verify/SKILL.md')));
        self::assertSame('verify-bundle', file_get_contents($this->project->path('.claude/skills/process-verify/SKILL.md')));
        self::assertSame('verify-bundle', file_get_contents($this->project->path('.agents/skills/process-verify/SKILL.md')));
        $contents = array_map(
            static fn (string $dir): string => (string) file_get_contents($dir . '/SKILL.md'),
            glob($this->project->path('.claude/.jardis-backup/process-verify*'), GLOB_ONLYDIR) ?: [],
        );
        sort($contents);
        self::assertSame(['user agents verify', 'user verify'], $contents);
    }

    public function testForeignAgentsSkillFolderStaysUntouched(): void
    {
        $this->project->writeFile('.agents/skills/foo/SKILL.md', 'foreign');
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'cache');

        $report = $this->install();

        self::assertSame([], $report->backedUpSkills());
        self::assertSame('foreign', file_get_contents($this->project->path('.agents/skills/foo/SKILL.md')));
        $read = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0');
        self::assertNotNull($read->manifest);
        self::assertArrayNotHasKey('.agents/skills/foo', $read->manifest->entries);
    }

    public function testAbortAfterStagingLeavesTargetsAndOldManifestUnchanged(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/alpha/SKILL.md', 'alpha-v1');
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/beta/SKILL.md', 'beta-v1');
        $this->install();
        $manifestBefore = (string) file_get_contents($this->project->path(Manifest::FILE));

        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/alpha/SKILL.md', 'alpha-v2');
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/beta/SKILL.md', 'beta-v2');
        // Real obstacle: a dangling link makes the copy of beta fail after alpha was staged.
        symlink(
            $this->project->path('vendor/none'),
            $this->project->path('vendor/jardisadapter/cache/.claude/skills/beta/broken'),
        );

        try {
            $this->install();
            self::fail('The install must abort on the uncopyable entry.');
        } catch (InstallFailedException) {
            // expected
        }

        foreach (['.claude/skills', '.agents/skills'] as $root) {
            self::assertSame('alpha-v1', file_get_contents($this->project->path($root . '/alpha/SKILL.md')));
            self::assertSame('beta-v1', file_get_contents($this->project->path($root . '/beta/SKILL.md')));
            self::assertSame(
                ['alpha', 'beta'],
                array_map('basename', glob($this->project->path($root) . '/*') ?: []),
            );
        }
        self::assertSame([], glob($this->project->path('.claude/skills') . '/.jardis-staging-*') ?: []);
        self::assertSame($manifestBefore, file_get_contents($this->project->path(Manifest::FILE)));
    }

    public function testLegacyBackupSiblingsAreMovedIntoTheBackupRoot(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'current');
        $this->project->writeFile('.claude/skills/adapter-cache.backup/SKILL.md', 'edited under 1.3.x');

        $this->install();

        self::assertSame([], $this->backupSiblings());
        self::assertSame(
            'edited under 1.3.x',
            file_get_contents($this->project->path('.claude/.jardis-backup/adapter-cache/SKILL.md')),
        );
    }

    public function testInstallLeavesNoBackupSiblingInEitherSkillFolder(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'old');
        $this->project->writeFile('.agents/skills/adapter-cache/SKILL.md', 'old');

        $this->install();

        self::assertSame([], $this->backupSiblings());
    }

    public function testAggregatesToSingleManagedBlockWhenSourceHasOwnBlock(): void
    {
        // foundation aggregates kernel, whose committed AGENTS.md already carries
        // its own managed block — its markers must not nest into the result.
        $this->project->writeFile(
            'vendor/jardiscore/foundation/AGENTS.md',
            "# foundation\nFoundation rules.\n",
        );
        $this->project->writeFile(
            'vendor/jardiscore/kernel/AGENTS.md',
            "# kernel\nKernel rules.\n\n"
            . AnalyzeAgentsMd::HEADER . "\n"
            . "kernel aggregated body\n"
            . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );
        $installer($this->project->root, $this->project->path('vendor'));

        $agents = file_get_contents($this->project->path('AGENTS.md'));

        self::assertSame(1, substr_count($agents, AnalyzeAgentsMd::HEADER));
        self::assertSame(1, substr_count($agents, AnalyzeAgentsMd::FOOTER));
        self::assertStringContainsString('Foundation rules.', $agents);
        self::assertStringContainsString('Kernel rules.', $agents);
        self::assertStringNotContainsString('kernel aggregated body', $agents);
    }

    public function testRepeatedInstallsAreIdempotentAndDoNotCascadeBackups(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            'cache-skill',
        );
        $this->project->writeFile(
            'vendor/jardiscore/kernel/AGENTS.md',
            "# kernel\nKernel rules.\n\n"
            . AnalyzeAgentsMd::HEADER . "\n"
            . "kernel aggregated body\n"
            . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );

        $installer($this->project->root, $this->project->path('vendor'));
        $afterFirst = file_get_contents($this->project->path('AGENTS.md'));

        $installer($this->project->root, $this->project->path('vendor'));
        $afterSecond = file_get_contents($this->project->path('AGENTS.md'));

        $installer($this->project->root, $this->project->path('vendor'));
        $afterThird = file_get_contents($this->project->path('AGENTS.md'));

        self::assertSame($afterFirst, $afterSecond);
        self::assertSame($afterSecond, $afterThird);
        self::assertSame(1, substr_count($afterThird, AnalyzeAgentsMd::HEADER));
        // No cascade: unchanged runs never create a backup of any kind.
        self::assertSame([], $this->backupSiblings());
        self::assertDirectoryDoesNotExist($this->project->path('.claude/.jardis-backup'));
    }

    /**
     * @return list<string> `*.backup*` entries in either skill folder
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

    private function install(string $pluginVersion = '0.0.0'): InstallReport
    {
        $installer = new SkillInstaller(config: PluginConfig::all(), pluginRoot: $this->pluginRepo->root);

        return $installer($this->project->root, $this->project->path('vendor'), $pluginVersion);
    }

    private function installer(PluginConfig $config): InstallReport
    {
        $installer = new SkillInstaller(config: $config, pluginRoot: $this->pluginRepo->root);

        return $installer($this->project->root, $this->project->path('vendor'));
    }

    public function testRunsOnEmptyVendorWithoutErrors(): void
    {
        $this->project->mkdir('vendor');

        $installer = new SkillInstaller(
            config: PluginConfig::all(),
            pluginRoot: $this->pluginRepo->root,
        );
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(0, $report->installedSkillCount());
        self::assertSame(0, $report->agentsFilesAggregated());
    }

    public function testSymlinkedAgentsSkillsWritesOnceAndDestroysNothing(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            'cache-skill',
        );
        $this->project->mkdir('.claude/skills');
        $this->project->mkdir('.agents');
        // Fixture setup only: the plugin itself never creates symlinks.
        self::assertTrue(symlink($this->project->path('.claude/skills'), $this->project->path('.agents/skills')));

        $installer = new SkillInstaller(config: PluginConfig::all(), pluginRoot: $this->pluginRepo->root);
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame([], $report->backedUpSkills());
        self::assertTrue(is_link($this->project->path('.agents/skills')));
        self::assertSame('cache-skill', file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')));
        self::assertSame('cache-skill', file_get_contents($this->project->path('.agents/skills/adapter-cache/SKILL.md')));
        self::assertSame([], $this->backupSiblings());

        unlink($this->project->path('.agents/skills'));
    }

    public function testBundleWinsOverVendorAndWarns(): void
    {
        $this->pluginRepo->writeFile('skills/shared-skill/SKILL.md', 'from-bundle');
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/shared-skill/SKILL.md', 'from-vendor');

        $installer = new SkillInstaller(config: PluginConfig::all(), pluginRoot: $this->pluginRepo->root);
        $report = $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame(1, $report->installedSkillCount());
        self::assertSame('from-bundle', file_get_contents($this->project->path('.claude/skills/shared-skill/SKILL.md')));
        self::assertSame('from-bundle', file_get_contents($this->project->path('.agents/skills/shared-skill/SKILL.md')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString($this->pluginRepo->path('skills/shared-skill'), $report->warnings()[0]);
        self::assertStringContainsString('jardisadapter/cache', $report->warnings()[0]);
    }

    public function testSecondRunLeavesSkillTreesByteIdentical(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md', 'cache-skill');
        $this->project->writeFile('vendor/jardisadapter/cache/.claude/skills/adapter-cache/nested/a.md', "a\r\nb");
        $this->pluginRepo->writeFile('skills/plan-requirements/SKILL.md', 'plan-skill');

        $installer = new SkillInstaller(config: PluginConfig::all(), pluginRoot: $this->pluginRepo->root);
        $installer($this->project->root, $this->project->path('vendor'));
        $first = $this->snapshot();
        $installer($this->project->root, $this->project->path('vendor'));

        self::assertSame($first, $this->snapshot());
    }

    public function testSourceContainsNoSymlinkCall(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                '/\bsymlink\s*\(/',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname(),
            );
        }
    }

    public function testInstallerHoldsNoBranching(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SkillInstaller.php');

        self::assertStringNotContainsString('if (', $source);
    }

    /**
     * @return array<string, string> relative path => sha1 of content, for both skill roots
     */
    private function snapshot(): array
    {
        $result = [];
        foreach (['.claude/skills', '.agents/skills'] as $root) {
            $base = $this->project->path($root);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                $result[$root . substr($file->getPathname(), strlen($base))] = sha1((string) file_get_contents($file->getPathname()));
            }
        }
        ksort($result);

        return $result;
    }
}
