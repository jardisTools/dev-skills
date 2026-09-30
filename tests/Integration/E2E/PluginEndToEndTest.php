<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\E2E;

use JardisTools\DevSkills\Tests\Support\ComposerFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
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
            $this->project->path('.claude/skills/rules-architecture/SKILL.md'),
            'Bundled skill rules-architecture was not copied.',
        );
        self::assertFileExists(
            $this->project->path('.claude/skills/platform-implementation/SKILL.md'),
            'Bundled skill platform-implementation was not copied.',
        );
        self::assertFileEquals(
            $this->project->path('.claude/skills/rules-architecture/SKILL.md'),
            $this->project->path('.agents/skills/rules-architecture/SKILL.md'),
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

    public function testComposerInstallWithoutBundledSkillsConfigInstallsCatalogAndVendorSkills(): void
    {
        // No bundled-skills key in extra → default-on: jardis-catalog,
        // jardis-start-here and jardis-mcp-consumer are installed, other bundle
        // skills (e.g. rules-architecture) are not.
        $this->writeConsumerComposerJson(bundledSkills: false);
        $this->runComposer('install');

        self::assertFileExists(
            $this->project->path('.claude/skills/adapter-fakecache/SKILL.md'),
            'Vendor skill must be installed even without explicit bundled-skills config.',
        );
        self::assertFileExists(
            $this->project->path('.claude/skills/jardis-catalog/SKILL.md'),
            'jardis-catalog must be installed by default (default-on) when bundled-skills key is absent.',
        );
        self::assertFileExists(
            $this->project->path('.claude/skills/jardis-start-here/SKILL.md'),
            'jardis-start-here must be installed by default (default-on) when bundled-skills key is absent.',
        );
        self::assertFileExists(
            $this->project->path('.claude/skills/jardis-mcp-consumer/SKILL.md'),
            'jardis-mcp-consumer must be installed by default (default-on) when bundled-skills key is absent.',
        );
        self::assertDirectoryDoesNotExist(
            $this->project->path('.claude/skills/rules-architecture'),
            'Other bundled skills must NOT be installed when bundled-skills key is absent.',
        );
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
            $this->project->path('.claude/skills/rules-architecture'),
            "Bundled skill should be cleaned up on plugin removal.\nComposer output:\n" . $output,
        );
        self::assertFileDoesNotExist(
            $this->project->path('AGENTS.md'),
            "AGENTS.md containing only the managed block should be deleted on plugin removal.\nRemaining AGENTS.md content:\n" . $remaining . "\n---\nComposer output:\n" . $output,
        );
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
