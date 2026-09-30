<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RemoveClaudeMdImportTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testRemovesBlockAndKeepsRest(): void
    {
        $original = "# Project\n\nMy rules.\n";
        $this->project->writeFile('CLAUDE.md', $original);
        AddonFactory::ensureClaudeMd()($this->project->root, $this->project->path('vendor'), new InstallReport());
        self::assertNotSame($original, file_get_contents($this->project->path('CLAUDE.md')));

        $report = $this->remove();

        self::assertSame($original, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertSame([], $report->warnings());
    }

    public function testRemovesBlockFromACrlfFileByteEqual(): void
    {
        $original = "# Project\r\n\r\nMy rules.\r\n";
        $this->project->writeFile('CLAUDE.md', $original);
        AddonFactory::ensureClaudeMd()($this->project->root, $this->project->path('vendor'), new InstallReport());

        $this->remove();

        self::assertSame($original, file_get_contents($this->project->path('CLAUDE.md')));
    }

    public function testKeepsTextAfterTheBlock(): void
    {
        $this->project->writeFile('CLAUDE.md', sprintf(
            "# Top\n\n%s\n@AGENTS.md\n%s\n\n# Bottom\n",
            AnalyzeAgentsMd::HEADER,
            AnalyzeAgentsMd::FOOTER,
        ));

        $this->remove();

        self::assertSame("# Top\n\n# Bottom\n", file_get_contents($this->project->path('CLAUDE.md')));
    }

    public function testDeletesFileWhenOnlyWhitespaceRemains(): void
    {
        AddonFactory::ensureClaudeMd()($this->project->root, $this->project->path('vendor'), new InstallReport());
        self::assertFileExists($this->project->path('CLAUDE.md'));

        $this->remove();

        self::assertFileDoesNotExist($this->project->path('CLAUDE.md'));
    }

    public function testKeepsImportStandingOutsideBlock(): void
    {
        $original = "# Project\n\n@AGENTS.md\n";
        $this->project->writeFile('CLAUDE.md', $original);

        $report = $this->remove();

        self::assertSame($original, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertSame([], $report->warnings());
    }

    public function testCorruptMarkersLeaveFileUntouched(): void
    {
        $original = "# Project\n" . AnalyzeAgentsMd::FOOTER . "\n@AGENTS.md\n";
        $this->project->writeFile('CLAUDE.md', $original);

        $report = $this->remove();

        self::assertSame($original, file_get_contents($this->project->path('CLAUDE.md')));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('corrupt managed-block markers', $report->warnings()[0]);
    }

    public function testLinkToAgentsMdIsLeftToTheAgentsMdRemoval(): void
    {
        $agents = AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n";
        $this->project->writeFile('AGENTS.md', $agents);
        self::assertTrue(symlink('AGENTS.md', $this->project->path('CLAUDE.md')));

        $this->remove();

        self::assertSame($agents, file_get_contents($this->project->path('AGENTS.md')));
        self::assertTrue(is_link($this->project->path('CLAUDE.md')));
    }

    private function remove(): UninstallReport
    {
        $report = new UninstallReport();
        AddonFactory::removeClaudeMd()($this->project->root, null, $report);

        return $report;
    }
}
