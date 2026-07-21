<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Install\StripManagedBlock;
use PHPUnit\Framework\TestCase;

final class StripManagedBlockTest extends TestCase
{
    private const HEADER = AnalyzeAgentsMd::HEADER;
    private const FOOTER = AnalyzeAgentsMd::FOOTER;

    public function testRemovesManagedBlockAndKeepsSurroundingContent(): void
    {
        $content = "# kernel\n\nKernel rules.\n\n"
            . self::HEADER . "\n"
            . "aggregated junk\n"
            . self::FOOTER . "\n\n"
            . "Footer note.\n";

        $stripped = (new StripManagedBlock())($content);

        self::assertStringNotContainsString(self::HEADER, $stripped);
        self::assertStringNotContainsString(self::FOOTER, $stripped);
        self::assertStringContainsString('Kernel rules.', $stripped);
        self::assertStringContainsString('Footer note.', $stripped);
        self::assertStringNotContainsString('aggregated junk', $stripped);
    }

    public function testReturnsContentUnchangedWhenNoMarkers(): void
    {
        $content = "# cache\n\nCache rules.\n";

        self::assertSame($content, (new StripManagedBlock())($content));
    }

    public function testCollapsesNestedBlockToMarkerFreeResult(): void
    {
        $content = "intro\n"
            . self::HEADER . "\n"
            . "outer\n"
            . self::HEADER . "\n"
            . "inner\n"
            . self::FOOTER . "\n"
            . "still outer\n"
            . self::FOOTER . "\n"
            . "outro\n";

        $stripped = (new StripManagedBlock())($content);

        self::assertStringNotContainsString(self::HEADER, $stripped);
        self::assertStringNotContainsString(self::FOOTER, $stripped);
        self::assertStringContainsString('intro', $stripped);
        self::assertStringContainsString('outro', $stripped);
    }

    public function testReturnsContentUnchangedWhenOnlyHeaderPresent(): void
    {
        $content = "intro\n" . self::HEADER . "\nbody\n";

        self::assertSame($content, (new StripManagedBlock())($content));
    }

    public function testReturnsContentUnchangedWhenMarkersOutOfOrder(): void
    {
        $content = "intro\n" . self::FOOTER . "\nbody\n" . self::HEADER . "\noutro\n";

        self::assertSame($content, (new StripManagedBlock())($content));
    }

    public function testIgnoresInlineMarkerMentions(): void
    {
        $content = "- the marker `" . self::HEADER . "` is mentioned inline\n";

        self::assertSame($content, (new StripManagedBlock())($content));
    }
}
