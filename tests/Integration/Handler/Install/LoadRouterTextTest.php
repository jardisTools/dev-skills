<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\LoadRouterText;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class LoadRouterTextTest extends TestCase
{
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
