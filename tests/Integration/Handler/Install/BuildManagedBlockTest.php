<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Install\BuildManagedBlock;
use PHPUnit\Framework\TestCase;

final class BuildManagedBlockTest extends TestCase
{
    public function testBuildsBlockWithHeaderFooterAndSources(): void
    {
        $block = (new BuildManagedBlock())([
            new AgentsDescriptor('jardisadapter/cache', "# cache\nCache rules."),
            new AgentsDescriptor('jardissupport/data', "# data\nData rules."),
        ]);

        self::assertStringStartsWith(AnalyzeAgentsMd::HEADER, $block);
        self::assertStringEndsWith(AnalyzeAgentsMd::FOOTER, $block);
        self::assertStringContainsString('<!-- source: jardisadapter/cache -->', $block);
        self::assertStringContainsString('<!-- source: jardissupport/data -->', $block);
        self::assertStringContainsString('Cache rules.', $block);
        self::assertStringContainsString('Data rules.', $block);
    }

    public function testBlockDoesNotEndWithTrailingNewline(): void
    {
        $block = (new BuildManagedBlock())([
            new AgentsDescriptor('jardisadapter/cache', 'x'),
        ]);

        self::assertSame(AnalyzeAgentsMd::FOOTER, substr($block, -strlen(AnalyzeAgentsMd::FOOTER)));
    }
}
