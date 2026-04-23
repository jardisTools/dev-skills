<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\E2E;

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

        // Plugin-own bundled skills were copied because bundled-skills: true.
        self::assertFileExists(
            $this->project->path('.claude/skills/rules-architecture/SKILL.md'),
            'Bundled skill rules-architecture was not copied.',
        );
        self::assertFileExists(
            $this->project->path('.claude/skills/platform-implementation/SKILL.md'),
            'Bundled skill platform-implementation was not copied.',
        );

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

    public function testComposerInstallWithoutBundledSkillsConfigInstallsOnlyVendorSkills(): void
    {
        $this->writeConsumerComposerJson(bundledSkills: false);
        $this->runComposer('install');

        self::assertFileExists(
            $this->project->path('.claude/skills/adapter-fakecache/SKILL.md'),
            'Vendor skill must be installed even with bundled-skills disabled.',
        );
        self::assertDirectoryDoesNotExist(
            $this->project->path('.claude/skills/rules-architecture'),
            'Bundled skill must NOT be installed when bundled-skills is absent (default = none).',
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
        $extra = $bundledSkills
            ? ['jardis/dev-skills' => ['bundled-skills' => true]]
            : new \stdClass();

        $json = [
            'name'              => 'jardis-test/consumer',
            'description'       => 'E2E test consumer project',
            'type'              => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable'     => true,
            'repositories'      => [
                [
                    'type'    => 'path',
                    'url'     => $this->pluginRoot,
                    'options' => ['symlink' => false],
                ],
                [
                    'type'    => 'path',
                    'url'     => $this->fakeVendorRoot,
                    'options' => ['symlink' => false],
                ],
            ],
            'require' => [
                'jardis/dev-skills'         => '*',
                'jardisadapter/fakecache'   => '*',
            ],
            'config' => [
                'allow-plugins' => [
                    'jardis/dev-skills' => true,
                ],
            ],
            'extra' => $extra,
        ];

        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Could not encode consumer composer.json.');
        }

        $this->project->writeFile('composer.json', $encoded);
    }

    private function runComposer(string $command): string
    {
        $cmd = sprintf(
            'cd %s && COMPOSER_HOME=%s composer %s --no-interaction --no-progress 2>&1',
            escapeshellarg($this->project->root),
            escapeshellarg($this->project->root . '/.composer-home'),
            $command,
        );

        $output    = [];
        $exitCode  = 0;
        exec($cmd, $output, $exitCode);
        $joined = implode("\n", $output);

        if ($exitCode !== 0) {
            self::fail(sprintf(
                "Composer command failed (exit %d): composer %s\nOutput:\n%s",
                $exitCode,
                $command,
                $joined,
            ));
        }

        return $joined;
    }
}
