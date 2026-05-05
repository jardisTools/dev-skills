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
            PluginConfig::filtered([], ['tools-*']),
        );

        $names = array_map(static fn (SkillDescriptor $s): string => $s->name, $kept);
        self::assertContains('schema-authoring', $names);
        self::assertContains('rules-architecture', $names);
        self::assertNotContains('tools-definition', $names);
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
        self::assertNotContains('tools-definition', $names);
    }

    public function testNoMatchesYieldsEmptyList(): void
    {
        $kept = (new FilterBundledSkills())(
            $this->bundled(),
            PluginConfig::filtered(['nonexistent-*'], []),
        );

        self::assertSame([], $kept);
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
                'platform-implementation',
                'rules-architecture',
                'rules-patterns',
                'schema-authoring',
                'tools-definition',
            ],
        );
    }
}
