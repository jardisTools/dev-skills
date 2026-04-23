<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\AggregateAgentsMd;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class AggregateAgentsMdTest extends TestCase
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

    public function testWritesManagedAgentsMd(): void
    {
        $descriptors = [
            new AgentsDescriptor('jardisadapter/cache', "# cache\nCache rules."),
            new AgentsDescriptor('jardissupport/data', "# data\nData rules."),
        ];

        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root);

        self::assertSame(2, $result->aggregatedCount);
        self::assertNull($result->backupPath);
        $content = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringContainsString('BEGIN jardis/dev-skills', $content);
        self::assertStringContainsString('END jardis/dev-skills', $content);
        self::assertStringContainsString('source: jardisadapter/cache', $content);
        self::assertStringContainsString('Cache rules.', $content);
        self::assertStringContainsString('Data rules.', $content);
    }

    public function testReturnsZeroAndSkipsFileWhenNoDescriptors(): void
    {
        $result = (new AggregateAgentsMd(new Filesystem()))([], $this->project->root);

        self::assertSame(0, $result->aggregatedCount);
        self::assertNull($result->backupPath);
        self::assertFileDoesNotExist($this->project->path('AGENTS.md'));
    }

    public function testBacksUpExistingUserAgentsMdOnFirstRun(): void
    {
        $userContent = "# My hand-written AGENTS\n\nRules here.\n";
        $this->project->writeFile('AGENTS.md', $userContent);

        $descriptors = [new AgentsDescriptor('jardisadapter/cache', '# cache')];
        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root);

        self::assertSame($this->project->path('AGENTS.md.backup'), $result->backupPath);
        self::assertFileExists($this->project->path('AGENTS.md.backup'));
        self::assertSame($userContent, file_get_contents($this->project->path('AGENTS.md.backup')));

        $written = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringStartsWith('# My hand-written AGENTS', $written);
        self::assertStringContainsString(AnalyzeAgentsMd::HEADER, $written);
        self::assertStringContainsString(AnalyzeAgentsMd::FOOTER, $written);
    }

    public function testReplacesManagedBlockInPlaceOnReRun(): void
    {
        $existing = "# User top\n\n"
            . AnalyzeAgentsMd::HEADER . "\nold managed body\n" . AnalyzeAgentsMd::FOOTER
            . "\n\n# User bottom\n";
        $this->project->writeFile('AGENTS.md', $existing);

        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'fresh content')];
        $result = (new AggregateAgentsMd(new Filesystem()))($descriptors, $this->project->root);

        self::assertNull($result->backupPath);
        self::assertFileDoesNotExist($this->project->path('AGENTS.md.backup'));

        $written = file_get_contents($this->project->path('AGENTS.md'));
        self::assertStringStartsWith("# User top\n\n", $written);
        self::assertStringEndsWith("# User bottom\n", $written);
        self::assertStringContainsString('fresh content', $written);
        self::assertStringNotContainsString('old managed body', $written);
    }

    public function testThrowsOnCorruptMarkers(): void
    {
        $this->project->writeFile(
            'AGENTS.md',
            "head\n" . AnalyzeAgentsMd::HEADER . "\nno footer here\n",
        );

        $this->expectException(InstallFailedException::class);
        (new AggregateAgentsMd(new Filesystem()))(
            [new AgentsDescriptor('jardisadapter/cache', 'x')],
            $this->project->root,
        );
    }
}
