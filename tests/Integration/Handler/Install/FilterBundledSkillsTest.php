<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\FilterBundledSkills;
use PHPUnit\Framework\TestCase;

final class FilterBundledSkillsTest extends TestCase
{
    public function testInstallNoneReturnsEmptyList(): void
    {
        $kept = (new FilterBundledSkills())($this->bundled(), PluginConfig::none());

        self::assertSame([], $kept);
    }

    public function testInstallAllReturnsAllUnchanged(): void
    {
        $bundled = $this->bundled();
        $kept = (new FilterBundledSkills())($bundled, PluginConfig::all());

        self::assertSame($bundled, $kept);
    }

    public function testIncludeGlobKeepsOnlyMatches(): void
    {
        $kept = (new FilterBundledSkills())(
            $this->bundled(),
            PluginConfig::filtered(['schema-*'], []),
        );

        self::assertSame(
            ['schema-authoring'],
            array_map(static fn (SkillDescriptor $s): string => $s->name, $kept),
        );
    }

    public function testExcludeRemovesMatchesWhenIncludeIsEmpty(): void
    {
        $kept = (new FilterBundledSkills())(
            $this->bundled(),
            PluginConfig::filtered([], ['rules-*']),
        );

        $names = array_map(static fn (SkillDescriptor $s): string => $s->name, $kept);
        self::assertContains('schema-authoring', $names);
        self::assertContains('platform-implementation', $names);
        self::assertNotContains('rules-architecture', $names);
        self::assertNotContains('rules-patterns', $names);
    }

    public function testIncludeThenExcludeCombines(): void
    {
        $kept = (new FilterBundledSkills())(
            $this->bundled(),
            PluginConfig::filtered(['schema-*', 'rules-*'], ['rules-patterns']),
        );

        $names = array_map(static fn (SkillDescriptor $s): string => $s->name, $kept);
        self::assertContains('schema-authoring', $names);
        self::assertContains('rules-architecture', $names);
        self::assertNotContains('rules-patterns', $names);
        self::assertNotContains('platform-implementation', $names);
    }

    public function testNoMatchesYieldsEmptyList(): void
    {
        $kept = (new FilterBundledSkills())(
            $this->bundled(),
            PluginConfig::filtered(['nonexistent-*'], []),
        );

        self::assertSame([], $kept);
    }

    public function testDefaultOnConfigKeepsDefaultSkills(): void
    {
        // PluginConfig::defaultOn() = include:['jardis-catalog','jardis-start-here',
        // 'jardis-mcp-consumer'], exclude:[]. Only these three pass the filter;
        // all other bundled skills are dropped.
        $kept = (new FilterBundledSkills())($this->bundled(), PluginConfig::defaultOn());

        $names = array_map(static fn (SkillDescriptor $s): string => $s->name, $kept);
        self::assertSame(['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer'], $names);
        self::assertNotContains('platform-implementation', $names);
        self::assertNotContains('rules-architecture', $names);
        self::assertNotContains('rules-patterns', $names);
        self::assertNotContains('schema-authoring', $names);
    }

    public function testExcludeJardisCatalogOptOut(): void
    {
        // When the user sets exclude: ['jardis-catalog'] the catalog is removed
        // while other bundled skills remain (include empty = "all except excluded").
        $kept = (new FilterBundledSkills())(
            $this->bundled(),
            PluginConfig::filtered([], ['jardis-catalog']),
        );

        $names = array_map(static fn (SkillDescriptor $s): string => $s->name, $kept);
        self::assertNotContains('jardis-catalog', $names);
        self::assertContains('rules-architecture', $names);
        self::assertContains('platform-implementation', $names);
    }

    /**
     * @return list<SkillDescriptor>
     */
    private function bundled(): array
    {
        return array_map(
            static fn (string $name): SkillDescriptor
                => new SkillDescriptor($name, '/irrelevant/' . $name, 'jardis/dev-skills'),
            [
                'jardis-catalog',
                'jardis-start-here',
                'jardis-mcp-consumer',
                'platform-implementation',
                'rules-architecture',
                'rules-patterns',
                'schema-authoring',
            ],
        );
    }
}
