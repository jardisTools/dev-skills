<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Exception\UninstallFailedException;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Uninstall\RemoveAggregatedAgentsMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RemoveAggregatedAgentsMdTest extends TestCase
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

    public function testDeletesFileWhenOnlyManagedBlockIsPresent(): void
    {
        $this->project->writeFile(
            'AGENTS.md',
            AnalyzeAgentsMd::HEADER . "\ncontent\n" . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $action = (new RemoveAggregatedAgentsMd())($this->project->root);

        self::assertSame(AgentsMdUninstallAction::FileDeleted, $action);
        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testStripsBlockAndKeepsUserContent(): void
    {
        $this->project->writeFile('AGENTS.md', sprintf(
            "# User top\n\n%s\nbody\n%s\n\n# User bottom\n",
            AnalyzeAgentsMd::HEADER,
            AnalyzeAgentsMd::FOOTER,
        ));

        $action = (new RemoveAggregatedAgentsMd())($this->project->root);

        self::assertSame(AgentsMdUninstallAction::BlockStripped, $action);
        $remaining = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('# User top', $remaining);
        self::assertStringContainsString('# User bottom', $remaining);
        self::assertStringNotContainsString(AnalyzeAgentsMd::HEADER, $remaining);
        self::assertStringNotContainsString(AnalyzeAgentsMd::FOOTER, $remaining);
        self::assertStringNotContainsString('body', $remaining);
    }

    public function testKeepsUserOwnedAgentsMd(): void
    {
        $this->project->writeFile('AGENTS.md', "# My hand-written AGENTS\n");

        $action = (new RemoveAggregatedAgentsMd())($this->project->root);

        self::assertSame(AgentsMdUninstallAction::Untouched, $action);
        self::assertFileExists($this->project->path('AGENTS.md'));
    }

    public function testReturnsUntouchedWhenMissing(): void
    {
        $action = (new RemoveAggregatedAgentsMd())($this->project->root);

        self::assertSame(AgentsMdUninstallAction::Untouched, $action);
    }

    public function testReportsCorruptAndKeepsFile(): void
    {
        $corrupt = "head\n" . AnalyzeAgentsMd::HEADER . "\nno footer\n";
        $this->project->writeFile('AGENTS.md', $corrupt);

        $action = (new RemoveAggregatedAgentsMd())($this->project->root);

        self::assertSame(AgentsMdUninstallAction::Corrupt, $action);
        self::assertSame($corrupt, file_get_contents($this->project->path('AGENTS.md')));
    }

    public function testThrowsWhenFileDeleteFails(): void
    {
        $this->project->writeFile(
            'AGENTS.md',
            AnalyzeAgentsMd::HEADER . "\nbody\n" . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $handler = new RemoveAggregatedAgentsMd(
            unlink: static fn (string $p): bool => false,
        );

        $this->expectException(UninstallFailedException::class);
        $this->expectExceptionMessage('Could not delete AGENTS.md');
        $handler($this->project->root);
    }

    public function testThrowsWhenBlockStripWriteFails(): void
    {
        $this->project->writeFile('AGENTS.md', sprintf(
            "# User\n\n%s\nbody\n%s\n",
            AnalyzeAgentsMd::HEADER,
            AnalyzeAgentsMd::FOOTER,
        ));

        $handler = new RemoveAggregatedAgentsMd(
            write: static fn (string $p, string $c): int|false => false,
        );

        $this->expectException(UninstallFailedException::class);
        $this->expectExceptionMessage('Could not strip managed block');
        $handler($this->project->root);
    }
}
