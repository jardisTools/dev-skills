<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class AnalyzeAgentsMdTest extends TestCase
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

    public function testReportsMissingFile(): void
    {
        $analysis = (new AnalyzeAgentsMd())($this->project->path('AGENTS.md'));

        self::assertFalse($analysis->fileExisted);
        self::assertFalse($analysis->hasManagedBlock);
        self::assertSame('', $analysis->preBlock);
        self::assertSame('', $analysis->postBlock);
    }

    public function testReportsUserOwnedFileWithoutMarkers(): void
    {
        $content = "# My hand-written AGENTS\n\nSome rules.\n";
        $path = $this->project->writeFile('AGENTS.md', $content);

        $analysis = (new AnalyzeAgentsMd())($path);

        self::assertTrue($analysis->fileExisted);
        self::assertFalse($analysis->hasManagedBlock);
        self::assertSame($content, $analysis->preBlock);
        self::assertSame('', $analysis->postBlock);
    }

    public function testSplitsAroundManagedBlock(): void
    {
        $path = $this->project->writeFile('AGENTS.md', sprintf(
            "# Header\nA line.\n\n%s\nmanaged\n%s\n\n# Footer\nAnother line.\n",
            AnalyzeAgentsMd::HEADER,
            AnalyzeAgentsMd::FOOTER,
        ));

        $analysis = (new AnalyzeAgentsMd())($path);

        self::assertTrue($analysis->fileExisted);
        self::assertTrue($analysis->hasManagedBlock);
        self::assertSame("# Header\nA line.\n\n", $analysis->preBlock);
        self::assertSame("\n\n# Footer\nAnother line.\n", $analysis->postBlock);
    }

    public function testThrowsWhenOnlyHeaderPresent(): void
    {
        $path = $this->project->writeFile(
            'AGENTS.md',
            "head\n" . AnalyzeAgentsMd::HEADER . "\ntrailing\n",
        );

        $this->expectException(InstallFailedException::class);
        (new AnalyzeAgentsMd())($path);
    }

    public function testThrowsWhenOnlyFooterPresent(): void
    {
        $path = $this->project->writeFile(
            'AGENTS.md',
            "head\n" . AnalyzeAgentsMd::FOOTER . "\ntrailing\n",
        );

        $this->expectException(InstallFailedException::class);
        (new AnalyzeAgentsMd())($path);
    }

    public function testThrowsWhenMultipleMarkerPairsPresent(): void
    {
        $path = $this->project->writeFile('AGENTS.md', sprintf(
            "%s\na\n%s\n%s\nb\n%s\n",
            AnalyzeAgentsMd::HEADER,
            AnalyzeAgentsMd::FOOTER,
            AnalyzeAgentsMd::HEADER,
            AnalyzeAgentsMd::FOOTER,
        ));

        $this->expectException(InstallFailedException::class);
        (new AnalyzeAgentsMd())($path);
    }

    public function testThrowsWhenMarkersInWrongOrder(): void
    {
        $path = $this->project->writeFile('AGENTS.md', sprintf(
            "%s\nmiddle\n%s\n",
            AnalyzeAgentsMd::FOOTER,
            AnalyzeAgentsMd::HEADER,
        ));

        $this->expectException(InstallFailedException::class);
        (new AnalyzeAgentsMd())($path);
    }

    public function testIgnoresMarkerStringsMentionedInlineInsideUserContent(): void
    {
        // A vendor's AGENTS.md may legitimately mention the marker string as
        // documentation. Such inline mentions must NOT be counted as markers.
        $body = "# Aggregated content\n\n"
            . "- Discussion of markers like `" . AnalyzeAgentsMd::HEADER . "` and `" . AnalyzeAgentsMd::FOOTER . "` inline.\n";

        $path = $this->project->writeFile('AGENTS.md', sprintf(
            "%s\n%s\n%s\n",
            AnalyzeAgentsMd::HEADER,
            $body,
            AnalyzeAgentsMd::FOOTER,
        ));

        $analysis = (new AnalyzeAgentsMd())($path);

        self::assertTrue($analysis->fileExisted);
        self::assertTrue($analysis->hasManagedBlock);
        self::assertSame('', $analysis->preBlock);
        self::assertSame("\n", $analysis->postBlock);
    }

    public function testIgnoresMarkerSubstringWithLeadingTextOnSameLine(): void
    {
        // "prefix <!-- BEGIN ... -->" is not a marker; the line does not equal the marker.
        $path = $this->project->writeFile('AGENTS.md', sprintf(
            "Note: see prefix %s for details.\n",
            AnalyzeAgentsMd::HEADER,
        ));

        $analysis = (new AnalyzeAgentsMd())($path);

        self::assertTrue($analysis->fileExisted);
        self::assertFalse($analysis->hasManagedBlock);
    }
}
