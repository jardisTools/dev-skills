<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Shell\WriteReviewerShells;
use JardisTools\DevSkills\SkillInstaller;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

/**
 * The `hosts` key: reviewer shells only for the listed tools (default `claude`), the config is the source of
 * truth for what stays, and `.gemini/settings.json` follows `gemini` in the list.
 */
final class SkillInstallerHostsTest extends TestCase
{
    private const ROLE = 'test-reviewer';

    private TempProject $project;
    private TempProject $plugin;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-hosts-project-');
        $this->plugin = new TempProject('dev-skills-hosts-plugin-');
        GitRepo::init($this->project->root);
        $this->plugin->writeFile('skills/foundation-alpha/SKILL.md', 'alpha');
        $this->plugin->writeFile('router/AGENTS-router.md', "Router text.\n");
        $this->plugin->writeFile(
            WriteReviewerShells::SOURCE_DIR . '/' . self::ROLE . '.md',
            (string) file_get_contents(__DIR__ . '/../Fixture/Reviewers/' . self::ROLE . '.md'),
        );
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->plugin->cleanup();
    }

    public function testDefaultWritesOnlyTheClaudeShells(): void
    {
        $report = $this->install([]);

        self::assertSame([], $report->warnings());
        self::assertFileExists($this->project->path('.claude/agents/' . self::ROLE . '.md'));
        foreach (['.codex', '.cursor', '.github', '.gemini'] as $folder) {
            self::assertDirectoryDoesNotExist($this->project->path($folder), $folder);
        }
        self::assertSame(['.claude/agents/' . self::ROLE . '.md'], $this->shellKeys());
        // The shared layer stays whatever `hosts` says.
        self::assertFileExists($this->project->path('.agents/skills/foundation-alpha/SKILL.md'));
        self::assertFileExists($this->project->path('AGENTS.md'));
        self::assertFileExists($this->project->path('CLAUDE.md'));
    }

    public function testClaudeAndCodexGetTheirShellsAndNobodyElse(): void
    {
        $this->install(['hosts' => ['claude', 'codex']]);

        self::assertFileExists($this->project->path('.claude/agents/' . self::ROLE . '.md'));
        self::assertFileExists($this->project->path('.codex/agents/' . self::ROLE . '.toml'));
        foreach (['.cursor', '.github', '.gemini'] as $folder) {
            self::assertDirectoryDoesNotExist($this->project->path($folder), $folder);
        }
    }

    public function testDroppingAHostRemovesItsManagedShellsAndKeepsForeignFiles(): void
    {
        $this->install(['hosts' => ['claude', 'codex']]);
        $this->project->writeFile('.codex/agents/mine.toml', "name = \"mine\"\n");

        $report = $this->install(['hosts' => ['claude']]);

        self::assertSame([], $report->warnings());
        self::assertFileDoesNotExist($this->project->path('.codex/agents/' . self::ROLE . '.toml'));
        self::assertFileExists($this->project->path('.codex/agents/mine.toml'));
        self::assertFileExists($this->project->path('.claude/agents/' . self::ROLE . '.md'));
        self::assertSame(['.claude/agents/' . self::ROLE . '.md'], $this->shellKeys());
    }

    public function testDroppingAHostRemovesItsEmptiedFolders(): void
    {
        $this->install(['hosts' => ['claude', 'copilot']]);
        self::assertFileExists($this->project->path('.github/agents/' . self::ROLE . '.agent.md'));

        $this->install(['hosts' => ['claude']]);

        self::assertDirectoryDoesNotExist($this->project->path('.github/agents'));
        self::assertDirectoryDoesNotExist($this->project->path('.github'));
    }

    public function testASecondRunWithTheSameHostsChangesNothing(): void
    {
        $this->install(['hosts' => ['claude', 'cursor']]);
        $before = $this->shellKeys();

        $report = $this->install(['hosts' => ['claude', 'cursor']]);

        self::assertSame([], $report->warnings());
        self::assertSame($before, $this->shellKeys());
        self::assertCount(2, $before);
    }

    public function testShellOfAHostWithAForeignFileAtThePathIsNeverRemoved(): void
    {
        $this->project->writeFile('.cursor/agents/' . self::ROLE . '.md', "mine\n");
        $report = $this->install(['hosts' => ['claude', 'cursor']]);
        self::assertCount(1, $report->warnings());

        $this->install(['hosts' => ['claude']]);

        self::assertSame("mine\n", file_get_contents($this->project->path('.cursor/agents/' . self::ROLE . '.md')));
    }

    public function testGeminiSettingsOnlyComeWithGeminiInTheList(): void
    {
        $this->install([]);
        self::assertFileDoesNotExist($this->project->path('.gemini/settings.json'));
        self::assertArrayNotHasKey('.gemini/settings.json', $this->selfSet());

        $this->install(['hosts' => ['claude', 'gemini']]);
        self::assertFileExists($this->project->path('.gemini/settings.json'));
        self::assertFileExists($this->project->path('.gemini/agents/' . self::ROLE . '.md'));
        self::assertStringContainsString('AGENTS.md', (string) file_get_contents($this->project->path('.gemini/settings.json')));
    }

    public function testDroppingGeminiRemovesWhatThePluginCreatedInTheGeminiFolder(): void
    {
        $this->install(['hosts' => ['claude', 'gemini']]);
        self::assertFileExists($this->project->path('.gemini/settings.json'));

        $this->install(['hosts' => ['claude']]);

        self::assertFileDoesNotExist($this->project->path('.gemini/settings.json'));
        self::assertDirectoryDoesNotExist($this->project->path('.gemini'));
        self::assertArrayNotHasKey('.gemini/settings.json', $this->selfSet());
    }

    public function testAForeignGeminiSettingsFileIsNeverTouchedWithoutGemini(): void
    {
        $this->project->writeFile('.gemini/settings.json', "{\n  \"theme\": \"dark\"\n}\n");

        $this->install([]);

        self::assertSame("{\n  \"theme\": \"dark\"\n}\n", file_get_contents($this->project->path('.gemini/settings.json')));
    }

    public function testInvalidHostsWarnAndInstallOnlyClaude(): void
    {
        $report = $this->install(['hosts' => ['claude', 'vim']]);

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('hosts must be a list of', $report->warnings()[0]);
        self::assertSame(['.claude/agents/' . self::ROLE . '.md'], $this->shellKeys());
    }

    public function testEveryHostNameMapsToOneShellFormat(): void
    {
        $formats = array_map(
            static fn (\JardisTools\DevSkills\Data\Host $host): ShellFormat => $host->shellFormat(),
            \JardisTools\DevSkills\Data\Host::cases(),
        );

        self::assertSame(ShellFormat::cases(), $formats);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function install(array $settings): InstallReport
    {
        $extra = $settings === [] ? [] : ['jardis/dev-skills' => $settings];
        $config = (new ReadPluginConfig())($extra, 'acme/app');

        return (new SkillInstaller(config: $config, pluginRoot: $this->plugin->root))(
            $this->project->root,
            $this->project->path('vendor'),
            '1.13.0',
        );
    }

    /**
     * @return list<string> the manifest keys that are reviewer shells, sorted
     */
    private function shellKeys(): array
    {
        $keys = array_values(array_filter(
            array_map('strval', array_keys($this->selfSet())),
            static fn (string $path): bool => ShellFormat::fromPath($path) !== null,
        ));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, \JardisTools\DevSkills\Data\SelfSetEntry>
     */
    private function selfSet(): array
    {
        return (new ReadManifest())($this->project->path(Manifest::FILE), '1.13.0')->manifest?->selfSet ?? [];
    }
}
