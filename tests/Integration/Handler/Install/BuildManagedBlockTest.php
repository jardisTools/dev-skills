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

    public function testRouterTextStandsBetweenBeginMarkerAndPackagesHeading(): void
    {
        $block = (new BuildManagedBlock())(
            [new AgentsDescriptor('jardisadapter/cache', 'Cache rules.')],
            false,
            "# Router\nRoute here.",
        );

        $begin = strpos($block, AnalyzeAgentsMd::HEADER);
        $router = strpos($block, "# Router\nRoute here.");
        $heading = strpos($block, '# Jardis packages');
        $aggregated = strpos($block, 'Aggregated by `jardis/dev-skills`');

        self::assertSame(0, $begin);
        self::assertNotFalse($router);
        self::assertNotFalse($heading);
        self::assertGreaterThan($begin, $router);
        self::assertGreaterThan($router, $heading);
        self::assertGreaterThan($heading, $aggregated);
        self::assertLessThan(strpos($block, 'Cache rules.'), $aggregated);
    }

    public function testEmptyRouterTextLeavesBlockUnchanged(): void
    {
        $descriptors = [new AgentsDescriptor('jardisadapter/cache', 'Cache rules.')];

        self::assertSame(
            (new BuildManagedBlock())($descriptors, true),
            (new BuildManagedBlock())($descriptors, true, ''),
        );
    }

    public function testPoolPointerFromRouterTextAppearsExactlyOnce(): void
    {
        $pointer = 'Wissenspool: `.claude/wissen/INDEX.md` — vor Entscheiden lesen, Vermerk-Pflicht';

        $block = (new BuildManagedBlock())(
            [new AgentsDescriptor('jardisadapter/cache', 'Cache rules.')],
            true,
            "# Router\n" . $pointer,
        );

        self::assertSame(1, substr_count($block, $pointer));
        self::assertSame(1, substr_count($block, 'Wissenspool'));
    }
}
