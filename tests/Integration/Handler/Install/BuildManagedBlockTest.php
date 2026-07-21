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

    public function testCatalogPointerIncludedWhenCatalogInstalled(): void
    {
        $block = (new BuildManagedBlock())([], true);

        self::assertStringContainsString(BuildManagedBlock::CATALOG_POINTER, $block);
        self::assertStringStartsWith(AnalyzeAgentsMd::HEADER, $block);
        self::assertStringEndsWith(AnalyzeAgentsMd::FOOTER, $block);
    }

    public function testCatalogPointerAbsentWhenCatalogNotInstalled(): void
    {
        $block = (new BuildManagedBlock())([], false);

        self::assertStringNotContainsString(BuildManagedBlock::CATALOG_POINTER, $block);
    }

    public function testCatalogPointerAppearsExactlyOnceWithDescriptors(): void
    {
        $block = (new BuildManagedBlock())(
            [new AgentsDescriptor('jardisadapter/cache', 'Cache rules.')],
            true,
        );

        self::assertSame(1, substr_count($block, BuildManagedBlock::CATALOG_POINTER));
        self::assertStringContainsString('Cache rules.', $block);
    }
}
