<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The installation profile against the real bundle of this repo: a fresh project without a Jardis
 * package gets the core skills, a Jardis package pulls the rest in, its removal takes it out again,
 * and an installation from before the profile existed keeps everything.
 */
final class SkillInstallerProfileTest extends TestCase
{
    private const JARDIS_SKILLS = [
        'design-draft-schema', 'design-headless-mcp', 'design-model-capabilities', 'generated-code-extend', 'generated-code-recipes',
        'generated-code-versioning', 'generated-code-wire-transport', 'generated-code-workflow-api',
        'start-orientation',
    ];

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-profile-project-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testFreshInstallWithoutAJardisPackageInstallsTheCoreSkills(): void
    {
        $this->project->writeFile('vendor/acme/x/composer.json', '{}');

        $report = $this->install(PluginConfig::all());

        self::assertSame(InstallProfile::Core, $report->profile());
        self::assertSame(25, $report->installedSkillCount());
        self::assertCount(25, $this->skillFolders());
        foreach (self::JARDIS_SKILLS as $name) {
            self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/' . $name));
            self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/' . $name));
        }
        self::assertDirectoryExists($this->project->path('.claude/skills/packages-find-existing'));
        self::assertSame(InstallProfile::Core, $this->manifest()->profile);

        $agentsMd = (string) file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('## PHP projects', $agentsMd);
        self::assertStringNotContainsString('Jardis projects', $agentsMd);
        self::assertStringNotContainsString('profile:', $agentsMd);
        self::assertFileDoesNotExist($this->project->path('.claude/agents/plan-review-packages.md'));
        self::assertFileExists($this->project->path('.claude/agents/plan-review-php.md'));
    }

    public function testAddingAJardisPackagePullsTheJardisSkillsInAndRemovingItTakesThemOut(): void
    {
        $this->install(PluginConfig::all());
        self::assertCount(25, $this->skillFolders());

        $this->project->writeFile('vendor/jardisadapter/cache/composer.json', '{}');
        $report = $this->install(PluginConfig::all());

        self::assertSame(InstallProfile::Jardis, $report->profile());
        self::assertCount(34, $this->skillFolders());
        self::assertSame(InstallProfile::Jardis, $this->manifest()->profile);
        $agentsMd = (string) file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('## Jardis projects', $agentsMd);
        self::assertStringNotContainsString('## PHP projects', $agentsMd);
        self::assertFileExists($this->project->path('.claude/agents/plan-review-packages.md'));

        (new Filesystem())->removeDirectory($this->project->path('vendor/jardisadapter'));
        $report = $this->install(PluginConfig::all());

        self::assertSame(InstallProfile::Core, $report->profile());
        self::assertCount(25, $this->skillFolders());
        self::assertSame(self::JARDIS_SKILLS, $this->sorted($report->removedBundledSkills()));
        self::assertSame(InstallProfile::Core, $this->manifest()->profile);
    }

    public function testConfiguredProfileOverridesTheDetection(): void
    {
        $this->project->writeFile('vendor/jardisadapter/cache/composer.json', '{}');

        $core = $this->install((new ReadPluginConfig())(['jardis/dev-skills' => ['profile' => 'core']]));
        self::assertCount(25, $this->skillFolders());
        self::assertSame(InstallProfile::Core, $core->profile());

        $this->install((new ReadPluginConfig())(['jardis/dev-skills' => ['profile' => 'jardis']]));
        self::assertCount(34, $this->skillFolders());
    }

    public function testInstallationFromBeforeTheProfileKeepsEverythingInEveryRun(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);

        $first = $this->install(PluginConfig::all());

        self::assertSame(InstallProfile::Jardis, $first->profile());
        self::assertCount(34 + 18, $this->skillFolders(), 'the 34 skills plus the 18 redirects');
        self::assertNull($this->manifest()->profile, 'a kept installation is not marked');

        $before = TreeSnapshot::ofProject($this->project);
        $second = $this->install(PluginConfig::all());

        self::assertSame(InstallProfile::Jardis, $second->profile());
        self::assertSame($before, TreeSnapshot::ofProject($this->project));
        self::assertSame([], $second->removedBundledSkills());
    }

    public function testHealthyManifestWithoutProfileKeepsEverything(): void
    {
        $this->install(PluginConfig::all()->withProfile(InstallProfile::Jardis, null));
        $path = $this->project->path(Manifest::FILE);
        $document = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        unset($document['profile']);
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

        $report = $this->install(PluginConfig::all());

        self::assertSame(InstallProfile::Jardis, $report->profile());
        self::assertCount(34, $this->skillFolders());
        self::assertSame([], $report->removedBundledSkills());
    }

    public function testMandatoryOnlyConfigStaysMandatoryOnlyInTheCoreProfile(): void
    {
        $report = $this->install(PluginConfig::onlyMandatory());

        self::assertSame(InstallProfile::Core, $report->profile());
        foreach ($this->skillFolders() as $name) {
            self::assertMatchesRegularExpression('/^(foundation|process)-/', $name);
        }
    }

    /**
     * @return list<string>
     */
    private function skillFolders(): array
    {
        $names = array_map('basename', glob($this->project->path('.claude/skills') . '/*', GLOB_ONLYDIR) ?: []);
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    private function manifest(): Manifest
    {
        $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest;
        self::assertNotNull($manifest);

        return $manifest;
    }

    private function install(PluginConfig $config): InstallReport
    {
        $installer = new SkillInstaller(config: $config, pluginRoot: dirname(__DIR__, 2));

        return $installer($this->project->root, $this->project->path('vendor'));
    }
}
