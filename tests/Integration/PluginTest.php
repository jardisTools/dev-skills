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
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Plugin;
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
        self::assertFileExists($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
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
        self::assertStringContainsString('Cache rules.', $agentsMd);
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
            'platform-implementation',
            'platform-usage',
            'rules-architecture',
            'rules-frontend',
            'rules-patterns',
            'rules-testing',
            'schema-authoring',
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
        $plugin->activate($this->createComposer(), $this->createMock(IOInterface::class));
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/schema-authoring/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.agents/skills/rules-architecture/SKILL.md'));
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
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-architecture'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/schema-authoring'));
    }

    public function testBundledSkillsWhitelistInstallsSubset(): void
    {
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer([
                'jardis/dev-skills' => ['bundled-skills' => ['platform-*', 'schema-*']],
            ]),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/platform-implementation/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/schema-authoring/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-architecture'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-testing'));
    }

    public function testBundledSkillsIncludeExcludeCombines(): void
    {
        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer([
                'jardis/dev-skills' => [
                    'bundled-skills' => [
                        'include' => ['rules-*'],
                        'exclude' => ['rules-patterns'],
                    ],
                ],
            ]),
            $this->createMock(IOInterface::class),
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertFileExists($this->project->path('.claude/skills/rules-architecture/SKILL.md'));
        self::assertFileExists($this->project->path('.claude/skills/rules-testing/SKILL.md'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-patterns'));
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/schema-authoring'));
    }

    public function testStaleBundledSkillIsRemovedViaManifestWhenConfigNarrows(): void
    {
        // First run installs everything and writes the manifest; the user edits one skill;
        // then config narrows to schema-*: rules-architecture goes (backed up first).
        $first = new Plugin();
        $first->activate($this->createComposer($this->allBundledExtra()), $this->createMock(IOInterface::class));
        $first->onComposerRun($this->createMock(ScriptEvent::class));
        $this->project->writeFile('.claude/skills/rules-architecture/SKILL.md', '# edited by user');

        $messages = [];
        $io = $this->createMock(IOInterface::class);
        $io->method('writeError')->willReturnCallback(
            static function (mixed $message) use (&$messages): void {
                $messages[] = (string) $message;
            },
        );

        $plugin = new Plugin();
        $plugin->activate(
            $this->createComposer(['jardis/dev-skills' => ['bundled-skills' => ['schema-*']]]),
            $io,
        );
        $plugin->onComposerRun($this->createMock(ScriptEvent::class));

        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/rules-architecture'));
        self::assertDirectoryDoesNotExist($this->project->path('.agents/skills/rules-architecture'));
        self::assertSame(
            '# edited by user',
            file_get_contents($this->project->path('.claude/.jardis-backup/rules-architecture/SKILL.md')),
        );
        self::assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'bundled skill "rules-architecture" removed'),
        ));
        self::assertFileExists($this->project->path('.claude/skills/schema-authoring/SKILL.md'));
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

        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/schema-authoring'));
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
        self::assertDirectoryDoesNotExist($this->project->path('.claude/skills/schema-authoring'));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createComposer(array $extra = []): Composer
    {
        $vendorDir = $this->project->path('vendor');

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'vendor-dir' ? $vendorDir : null,
        );

        $package = $this->createMock(RootPackageInterface::class);
        $package->method('getExtra')->willReturn($extra);

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
        return ['jardis/dev-skills' => ['bundled-skills' => true]];
    }

    private function createPackageEvent(string $packageName): PackageEvent
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

        return $event;
    }
}
