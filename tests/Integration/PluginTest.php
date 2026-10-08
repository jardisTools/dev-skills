<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Plugin;
use JardisTools\DevSkills\Tests\Support\GitRepo;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    private TempProject $project;
    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('getcwd() failed in setUp');
        }

        $this->originalCwd = $cwd;
        $this->project = new TempProject('dev-skills-plugin-');
        // A real repository: the exclude block (P4.3) warns when the project has none.
        GitRepo::init($this->project->root);
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->project->cleanup();
    }

    public function testActivateInstantiatesOrchestratorsSoLaterCallsDoNotNullPointer(): void
    {
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer($this->allBundledExtra()),
            $this->createMock(IOInterface::class),
        );

        // Both subsequent handlers must run without null-pointer errors.
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/adapter/cache'));

        // Proof of activation: onComposerRun copied the plugin-own skills.
        self::assertFileExists($this->project->path('.claude/skills/foundation-architecture/SKILL.md'));
    }

    public function testOnComposerRunCopiesVendorSkillsAndAggregatesAgentsMd(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            "# adapter-cache\nbody",
        );
        $this->project->writeFile(
            'vendor/jardisadapter/cache/AGENTS.md',
            "# adapter-cache\nCache rules.",
        );

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $this->createMock(IOInterface::class));
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/adapter-cache/SKILL.md'));

        $agentsMd = (string) file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('BEGIN jardis/dev-skills', $agentsMd);
        // The block of a vendor package is the short form: the catalog capability (the plugin's own manifest knows
        // jardisadapter/cache) and the skill to load, not the package AGENTS.md itself.
        self::assertStringContainsString('Multi-layer caching', $agentsMd);
        self::assertStringContainsString('load skill `adapter-cache`', $agentsMd);
        self::assertStringNotContainsString('Cache rules.', $agentsMd);
    }

    public function testOnComposerRunInstallsAllPluginOwnSkills(): void
    {
        // No vendor packages — only the plugin's bundled skills/ directory.
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer($this->allBundledExtra()),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        $expected = [
            'generated-code-extend',
            'generated-code-wire-transport',
            'foundation-architecture',
            'foundation-frontend-review',
            'foundation-patterns',
            'foundation-testing',
            'design-draft-schema',
        ];

        foreach ($expected as $skill) {
            self::assertFileExists(
                $this->project->path(".claude/skills/{$skill}/SKILL.md"),
                "Plugin-own skill {$skill} was not copied.",
            );
        }
    }

    public function testOnPackageUninstallRemovesSelfManagedSkillsAndAgentsMd(): void
    {
        // Manifest-less 1.3.x install: the folder still carries its former bundle name.
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'x');
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', 'y');
        $this->project->writeFile('.claude/skills/my-local/SKILL.md', 'local');
        $this->project->writeFile(
            'AGENTS.md',
            AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $this->createMock(IOInterface::class));
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/dev-skills'));

        self::assertFileDoesNotExist($this->project->path('.claude/skills/adapter-cache/SKILL.md'));
        self::assertFileDoesNotExist($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/my-local/SKILL.md'));
        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testOnComposerRunWarnsWhenExistingAgentsMdIsBackedUp(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/AGENTS.md',
            "# adapter-cache\nCache rules.",
        );
        $this->project->writeFile('AGENTS.md', "# My hand-written AGENTS\n");

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('existing AGENTS.md moved to'));

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $io);
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('AGENTS.md.backup'));
    }

    public function testOnPackageUninstallWarnsOnCorruptAgentsMd(): void
    {
        $this->project->writeFile(
            'AGENTS.md',
            "top\n" . AnalyzeAgentsMd::HEADER . "\nno footer here\n",
        );

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('corrupt markers'));

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $io);
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/dev-skills'));

        self::assertFileExists($this->project->path('AGENTS.md'));
    }

    public function testOnPackageUninstallStripsManagedBlockAndKeepsUserContent(): void
    {
        $this->project->writeFile('AGENTS.md', sprintf(
            "# User top\n\n%s\nmanaged\n%s\n\n# User bottom\n",
            AnalyzeAgentsMd::HEADER,
            AnalyzeAgentsMd::FOOTER,
        ));

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $this->createMock(IOInterface::class));
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/dev-skills'));

        $remaining = (string) file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('# User top', $remaining);
        self::assertStringContainsString('# User bottom', $remaining);
        self::assertStringNotContainsString(AnalyzeAgentsMd::HEADER, $remaining);
    }

    public function testOnPackageUninstallIgnoresOtherPackages(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', 'x');
        $this->project->writeFile(
            'AGENTS.md',
            AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $this->createMock(IOInterface::class));
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/adapter/cache'));

        self::assertFileExists($this->project->path('.claude/skills/adapter-cache/SKILL.md'));
        self::assertFileExists($this->project->path('AGENTS.md'));
    }

    public function testOnComposerRunDoesNothingInTheGlobalContext(): void
    {
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer($this->allBundledExtra(), home: $this->project->root),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertSame([], glob($this->project->root . '/*') ?: []);
        self::assertDirectoryDoesNotExist($this->project->path('.claude'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents'));
    }

    public function testOnPackageUninstallDeletesNothingInTheGlobalContext(): void
    {
        $this->project->writeFile('.claude/skills/foundation-architecture/SKILL.md', 'y');

        $composer = $this->createComposer(home: $this->project->root);
        $plugin = new Plugin();
        $plugin->activate($composer, $this->createMock(IOInterface::class));
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/dev-skills', $composer));

        self::assertFileExists($this->project->path('.claude/skills/foundation-architecture/SKILL.md'));
    }

    public function testOnPackageUninstallDeletesNothingWhileTheRootPackageStillRequiresThePlugin(): void
    {
        $this->project->writeFile('.claude/skills/foundation-architecture/SKILL.md', 'y');
        $this->project->writeFile('AGENTS.md', AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n");

        $composer = $this->createComposer(requiresSelf: true);
        $plugin = new Plugin();
        $plugin->activate($composer, $this->createMock(IOInterface::class));
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/dev-skills', $composer));

        self::assertFileExists($this->project->path('.claude/skills/foundation-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('AGENTS.md'));
    }

    public function testGetSubscribedEventsReturnsExpectedMap(): void
    {
        $events = Plugin::getSubscribedEvents();

        self::assertSame('onComposerRun', $events[ScriptEvents::POST_INSTALL_CMD]);
        self::assertSame('onComposerRun', $events[ScriptEvents::POST_UPDATE_CMD]);
        self::assertSame('onPackageUninstall', $events[PackageEvents::PRE_PACKAGE_UNINSTALL]);
    }

    public function testDeactivateAndUninstallAreNoOps(): void
    {
        $plugin = new Plugin();
        $composer = $this->createComposer();
        $io = $this->createMock(IOInterface::class);

        $plugin->deactivate($composer, $io);
        $plugin->uninstall($composer, $io);

        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testOnComposerRunIsNoOpWhenNotActivated(): void
    {
        $plugin = new Plugin();
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testOnPackageUninstallIsNoOpWhenNotActivated(): void
    {
        $plugin = new Plugin();
        $plugin->onPackageUninstall($this->createPackageEvent('jardis/dev-skills'));

        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testOnPackageUninstallIgnoresNonUninstallOperation(): void
    {
        $this->project->writeFile(
            'AGENTS.md',
            AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $operation = $this->getMockBuilder(InstallOperation::class)
            ->disableOriginalConstructor()
            ->getMock();
        $event = $this->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $event->method('getOperation')->willReturn($operation);

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $this->createMock(IOInterface::class));
        $plugin->onPackageUninstall($event);

        self::assertFileExists($this->project->path('AGENTS.md'));
    }

    public function testOnComposerRunWarnsOnSkillConflict(): void
    {
        $this->project->writeFile('.claude/skills/adapter-cache/SKILL.md', '# existing');
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            '# vendor',
        );

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('existing skill "adapter-cache" differs from the managed state, saved to'));

        $plugin = new Plugin();
        $plugin->activate($this->createComposer(), $io);
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertSame('# existing', file_get_contents($this->project->path('.claude/.jardis-backup/adapter-cache/SKILL.md')));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/adapter-cache.backup'));
        self::assertSame('# vendor', file_get_contents($this->project->path('.claude/skills/adapter-cache/SKILL.md')));
    }

    public function testAbsentBundledSkillsKeyInstallsAllBundledSkills(): void
    {
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer(['jardis/dev-skills' => ['profile' => 'jardis']]),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/design-draft-schema/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/foundation-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.agents/skills/foundation-architecture/SKILL.md'));
    }

    public function testFalseWarnsAboutMandatoryGroupsAndInstallsNoOtherBundleSkill(): void
    {
        $messages = [];
        $io = $this->createMock(IOInterface::class);
        $io->method('writeError')->willReturnCallback(
            static function (mixed $message) use (&$messages): void {
                $messages[] = (string) $message;
            },
        );

        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer(['jardis/dev-skills' => ['bundled-skills' => false]]),
            $io,
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertContains(
            '<warning>jardis/dev-skills: bundled-skills=false: mandatory groups foundation-*/process-* '
            . 'are always installed</warning>',
            $messages,
        );
        self::assertDirectoryExists($this->project->path('.claude/skills/foundation-architecture'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/generated-code-extend'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/design-draft-schema'));
    }

    public function testBundledSkillsWhitelistInstallsSubset(): void
    {
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer([
                'jardis/dev-skills' => ['bundled-skills' => ['generated-code-*', 'design-*'], 'profile' => 'jardis'],
            ]),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/generated-code-extend/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/design-draft-schema/SKILL.md'));
        // foundation-* is a mandatory group: installed although the whitelist does not name it.
        self::assertFileExists($this->project->path('.claude/skills/foundation-architecture/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/git-commit-change'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/start-orientation'));
    }

    public function testBundledSkillsIncludeExcludeCombines(): void
    {
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer([
                'jardis/dev-skills' => [
                    'bundled-skills' => [
                        'include' => ['generated-code-*'],
                        'exclude' => ['generated-code-recipes'],
                    ],
                    'profile' => 'jardis',
                ],
            ]),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/generated-code-extend/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/generated-code-versioning/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/generated-code-recipes'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/design-draft-schema'));
    }

    public function testStaleBundledSkillIsRemovedViaManifestWhenConfigNarrows(): void
    {
        // First run installs everything and writes the manifest; the user edits one skill;
        // then config narrows to design-*: generated-code-extend goes (backed up first).
        $first = new Plugin();
        $first->activate($this->createComposer($this->allBundledExtra()), $this->createMock(IOInterface::class));
        $first->onComposerRun($this->createMock(ScriptEvent::class));
        $this->project->writeFile('.claude/skills/generated-code-extend/SKILL.md', '# edited by user');

        $messages = [];
        $io = $this->createMock(IOInterface::class);
        $io->method('writeError')->willReturnCallback(
            static function (mixed $message) use (&$messages): void {
                $messages[] = (string) $message;
            },
        );

        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer(['jardis/dev-skills' => ['bundled-skills' => ['design-*'], 'profile' => 'jardis']]),
            $io,
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/generated-code-extend'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/generated-code-extend'));
        self::assertSame(
            '# edited by user',
            file_get_contents($this->project->path('.claude/.jardis-backup/generated-code-extend/SKILL.md')),
        );
        self::assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'bundled skill "generated-code-extend" removed'),
        ));
        self::assertFileExists($this->project->path('.claude/skills/design-draft-schema/SKILL.md'));
    }

    public function testUserPrefixSkillsStayWhenBundledDisabled(): void
    {
        $this->project->writeFile('.claude/skills/my-local/SKILL.md', 'my code');
        $this->project->writeFile('.claude/skills/internal-stuff/SKILL.md', 'also mine');

        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer(['jardis/dev-skills' => ['bundled-skills' => false]]),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/my-local/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/internal-stuff/SKILL.md'));
    }

    public function testInvalidConfigEmitsWarningAndFallsBackToNone(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::atLeastOnce())
            ->method('writeError')
            ->with(self::stringContains('bundled-skills must be'));

        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer([
                'jardis/dev-skills' => ['bundled-skills' => 42],
            ]),
            $io,
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/design-draft-schema'));
    }

    public function testVendorSkillsStayEvenWhenBundledDisabled(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/.claude/skills/adapter-cache/SKILL.md',
            '# vendor',
        );

        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer(['jardis/dev-skills' => ['bundled-skills' => false]]),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/adapter-cache/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/design-draft-schema'));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createComposer(array $extra = [], ?string $home = null, bool $requiresSelf = false): Composer
    {
        $vendorDir = $this->project->path('vendor');

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'vendor-dir' => $vendorDir,
                'home' => $home,
                default => null,
            },
        );

        $package = $this->createMock(RootPackageInterface::class);
        $package->method('getExtra')->willReturn($extra);
        $package->method('getDevRequires')->willReturn($requiresSelf
            ? ['jardis/dev-skills' => new Link('consumer/app', 'jardis/dev-skills', new MatchAllConstraint())]
            : []);

        $composer = $this->createMock(Composer::class);
        $composer->method('getConfig')->willReturn($config);
        $composer->method('getPackage')->willReturn($package);

        return $composer;
    }

    /**
     * @return array<string, mixed>
     */
    private function allBundledExtra(): array
    {
        // The profile is named: these tests are about the selection of the config, not about the detection.
        return ['jardis/dev-skills' => ['bundled-skills' => true, 'profile' => 'jardis']];
    }

    private function createPackageEvent(string $packageName, ?Composer $composer = null): PackageEvent
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn($packageName);

        $operation = $this->getMockBuilder(UninstallOperation::class)
            ->disableOriginalConstructor()
            ->getMock();
        $operation->method('getPackage')->willReturn($package);

        $event = $this->getMockBuilder(PackageEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $event->method('getOperation')->willReturn($operation);
        if ($composer !== null) {
            $event->method('getComposer')->willReturn($composer);
        }

        return $event;
    }
}
