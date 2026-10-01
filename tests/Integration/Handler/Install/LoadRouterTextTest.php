<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\LoadRouterText;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class LoadRouterTextTest extends TestCase
{
    private const WITH_GIT_RULES = "# Router\nRoute here.\n\n<!-- git-rules -->\nGit rule.\n<!-- /git-rules -->\n\n## Tiers\nTable.\n";

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
            (new LoadRouterText())($this->plugin->root, true),
        );
    }

    public function testGitRulesOffDropsTheAreaAndTheMarkerLines(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', self::WITH_GIT_RULES);

        self::assertSame(
            "# Router\nRoute here.\n\n## Tiers\nTable.",
            (new LoadRouterText())($this->plugin->root, false),
        );
    }

    public function testRouterWithoutMarkersIsTheSameOnAndOff(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', "# Router\nRoute here.\n\n## Tiers\nTable.\n");

        self::assertSame("# Router\nRoute here.\n\n## Tiers\nTable.", (new LoadRouterText())($this->plugin->root, true));
        self::assertSame("# Router\nRoute here.\n\n## Tiers\nTable.", (new LoadRouterText())($this->plugin->root, false));
    }

    public function testMarkerLinesNeverReachTheResult(): void
    {
        $this->plugin->writeFile(
            'router/AGENTS-router.md',
            "<!-- git-rules -->\r\nGit rule.\r\n<!-- /git-rules -->\r\n\r\nTail.\r\n<!-- /git-rules -->\r\n",
        );

        foreach ([true, false] as $gitRules) {
            self::assertStringNotContainsString('git-rules', (new LoadRouterText())($this->plugin->root, $gitRules));
        }
        self::assertSame("Git rule.\n\nTail.", (new LoadRouterText())($this->plugin->root, true));
        self::assertSame('Tail.', (new LoadRouterText())($this->plugin->root, false));
    }

    public function testAreaWithoutItsEndMarkerStaysEvenWithTheOptOut(): void
    {
        $this->plugin->writeFile('router/AGENTS-router.md', "Intro.\n\n<!-- git-rules -->\nGit rule.\n\n## Tiers\n");

        self::assertSame("Intro.\n\nGit rule.\n\n## Tiers", (new LoadRouterText())($this->plugin->root, false));
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
}
