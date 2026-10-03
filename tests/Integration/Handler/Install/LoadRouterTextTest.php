<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\GitRulesMode;
use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\LoadRouterText;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class LoadRouterTextTest extends TestCase
{
    private const WITH_GIT_RULES = "# Router\nRoute here.\n\n<!-- git-rules -->\nGit rule.\n<!-- /git-rules -->\n\n## Tiers\nTable.\n";

    private const WITH_BOTH_AREAS = "# Router\nRoute here.\n\n<!-- git-rules -->\nStrict rule.\n<!-- /git-rules -->\n\n<!-- git-rules:delegated -->\nDelegated rule.\n<!-- /git-rules:delegated -->\n\n## Tiers\nTable.\n";

    private const WITH_PROFILE_AREAS = "# Router\nRoute here.\n\n<!-- profile:jardis -->\nJardis rule.\n<!-- /profile:jardis -->\n\n<!-- profile:core -->\nCore rule.\n<!-- /profile:core -->\n\n## Tiers\nTable.\n";

    private TempProject $plugin;

    protected function setUp(): void
    {
        $this->plugin = new TempProject('dev-skills-router-');
    }

    protected function tearDown(): void
    {
        $this->plugin->cleanup();
    }

    public function testMissingFileYieldsEmptySlot(): void
    {
        self::assertSame('', (new LoadRouterText())($this->plugin->root));
    }

    public function testReadsRouterTextTrimmedAndWithLfLineEndings(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', "\n# Router\r\nRoute here.\r\n\r\n");

        self::assertSame("# Router\nRoute here.", (new LoadRouterText())($this->plugin->root));
    }

    public function testEmptyFileYieldsEmptySlot(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', "\n\n");

        self::assertSame('', (new LoadRouterText())($this->plugin->root));
    }

    public function testGitRulesOnKeepsTheAreaAndDropsTheMarkerLines(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_GIT_RULES);

        self::assertSame(
            "# Router\nRoute here.\n\nGit rule.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root),
        );
        self::assertSame(
            (new LoadRouterText())($this->plugin->root),
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Strict),
        );
    }

    public function testGitRulesOffDropsTheAreaAndTheMarkerLines(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_GIT_RULES);

        self::assertSame(
            "# Router\nRoute here.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Off),
        );
    }

    public function testStrictKeepsTheFirstAreaAndDropsTheDelegatedOne(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_BOTH_AREAS);

        self::assertSame(
            "# Router\nRoute here.\n\nStrict rule.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Strict),
        );
        self::assertSame(
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Strict),
            (new LoadRouterText())($this->plugin->root),
        );
    }

    public function testDelegatedKeepsTheSecondAreaAndDropsTheStrictOne(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_BOTH_AREAS);

        self::assertSame(
            "# Router\nRoute here.\n\nDelegated rule.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Delegated),
        );
    }

    public function testOffDropsBothAreasAndEveryMarkerLine(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_BOTH_AREAS);

        self::assertSame(
            "# Router\nRoute here.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Off),
        );
    }

    public function testMarkerLinesOfEitherAreaNeverReachTheResult(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_BOTH_AREAS);

        foreach (GitRulesMode::cases() as $mode) {
            self::assertStringNotContainsString('git-rules', (new LoadRouterText())($this->plugin->root, $mode), $mode->name);
        }
    }

    public function testRouterWithoutMarkersIsTheSameInEveryStance(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', "# Router\nRoute here.\n\n## Tiers\nTable.\n");

        foreach (GitRulesMode::cases() as $mode) {
            self::assertSame("# Router\nRoute here.\n\n## Tiers\nTable.", (new LoadRouterText())($this->plugin->root, $mode), $mode->name);
        }
    }

    public function testMarkerLinesNeverReachTheResult(): void
    {
        $this->plugin->writeFile(
            'router/AGENTS-router.md',
            "<!-- git-rules -->\r\nGit rule.\r\n<!-- /git-rules -->\r\n\r\nTail.\r\n<!-- /git-rules -->\r\n",
        );

        foreach ([GitRulesMode::Strict, GitRulesMode::Off] as $gitRules) {
            self::assertStringNotContainsString('git-rules', (new LoadRouterText())($this->plugin->root, $gitRules));
        }
        self::assertSame("Git rule.\n\nTail.", (new LoadRouterText())($this->plugin->root, GitRulesMode::Strict));
        self::assertSame('Tail.', (new LoadRouterText())($this->plugin->root, GitRulesMode::Off));
    }

    public function testAreaWithoutItsEndMarkerStaysEvenWithTheOptOut(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', "Intro.\n\n<!-- git-rules -->\nGit rule.\n\n## Tiers\n");

        self::assertSame("Intro.\n\nGit rule.\n\n## Tiers", (new LoadRouterText())($this->plugin->root, GitRulesMode::Off));
    }

    public function testUnreadableRouterFileFailsAsCoreError(): void
    {
        $path = $this->plugin->writeFile('router/AGENTS-router.md', 'text');
        chmod($path, 0o000);
        if (is_readable($path)) {
            chmod($path, 0o644);
            self::markTestSkipped('File permissions are not enforced for this user.');
        }

        try {
            $this->expectException(InstallFailedException::class);
            (new LoadRouterText())($this->plugin->root);
        } finally {
            chmod($path, 0o644);
        }
    }

    public function testJardisProfileKeepsItsAreaAndDropsTheCoreOne(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_PROFILE_AREAS);

        self::assertSame(
            "# Router\nRoute here.\n\nJardis rule.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Strict, InstallProfile::Jardis),
        );
        self::assertSame(
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Strict, InstallProfile::Jardis),
            (new LoadRouterText())($this->plugin->root),
            'jardis is the default profile',
        );
    }

    public function testCoreProfileKeepsItsAreaAndDropsTheJardisOne(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_PROFILE_AREAS);

        self::assertSame(
            "# Router\nRoute here.\n\nCore rule.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root, GitRulesMode::Strict, InstallProfile::Core),
        );
    }

    public function testNoProfileOrGitMarkerReachesTheResultInAnyCombination(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_BOTH_AREAS . "\n" . self::WITH_PROFILE_AREAS);

        foreach (GitRulesMode::cases() as $mode) {
            foreach (InstallProfile::cases() as $profile) {
                $text = (new LoadRouterText())($this->plugin->root, $mode, $profile);

                self::assertStringNotContainsString('<!--', $text, $mode->name . '/' . $profile->name);
                self::assertStringNotContainsString("\n\n\n", $text, $mode->name . '/' . $profile->name);
            }
        }
    }
}
