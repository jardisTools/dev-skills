<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\FilterBundledSkills;
use JardisTools\DevSkills\Handler\Install\IsMandatorySkill;
use PHPUnit\Framework\TestCase;

/**
 * Fixture names only: the mandatory groups match `foundation-*` / `process-*`,
 * the bundle has no such skills yet.
 */
final class FilterBundledSkillsTest extends TestCase
{
    public function testAllReturnsEverythingUnchanged(): void
    {
        $bundled = $this->bundled();

        self::assertSame($bundled, $this->filter($bundled, PluginConfig::all()));
    }

    public function testOnlyMandatoryKeepsJustTheMandatoryGroups(): void
    {
        $kept = $this->filter($this->bundled(), PluginConfig::onlyMandatory());

        self::assertSame(['foundation-alpha', 'process-beta'], $this->names($kept));
    }

    public function testOnlyMandatoryWithoutMandatorySkillsKeepsNothing(): void
    {
        $kept = $this->filter([$this->skill('adapter-cache')], PluginConfig::onlyMandatory());

        self::assertSame([], $kept);
    }

    public function testIncludeGlobKeepsMatchesPlusMandatoryGroups(): void
    {
        $kept = $this->filter($this->bundled(), PluginConfig::filtered(['adapter-*'], []));

        self::assertSame(['foundation-alpha', 'process-beta', 'adapter-cache'], $this->names($kept));
    }

    public function testNoMatchingIncludeStillKeepsMandatoryGroups(): void
    {
        $kept = $this->filter($this->bundled(), PluginConfig::filtered(['nonexistent-*'], []));

        self::assertSame(['foundation-alpha', 'process-beta'], $this->names($kept));
    }

    public function testExcludeOnlyRemovesMatchesFromEverything(): void
    {
        $kept = $this->filter($this->bundled(), PluginConfig::filtered([], ['rules-*']));

        $names = $this->names($kept);
        self::assertContains('adapter-cache', $names);
        self::assertContains('foundation-alpha', $names);
        self::assertNotContains('rules-patterns', $names);
    }

    public function testIncludeThenExcludeCombines(): void
    {
        $kept = $this->filter($this->bundled(), PluginConfig::filtered(['adapter-*', 'rules-*'], ['rules-patterns']));

        $names = $this->names($kept);
        self::assertContains('adapter-cache', $names);
        self::assertContains('rules-testing', $names);
        self::assertNotContains('rules-patterns', $names);
        self::assertNotContains('schema-authoring', $names);
    }

    public function testExcludeCannotRemoveMandatoryGroups(): void
    {
        $kept = $this->filter(
            $this->bundled(),
            PluginConfig::filtered([], ['foundation-*', 'process-beta', 'rules-*']),
        );

        $names = $this->names($kept);
        self::assertContains('foundation-alpha', $names);
        self::assertContains('process-beta', $names);
        self::assertNotContains('rules-testing', $names);
    }

    /**
     * @param list<SkillDescriptor> $bundled
     * @return list<SkillDescriptor>
     */
    private function filter(array $bundled, PluginConfig $config): array
    {
        return (new FilterBundledSkills((new IsMandatorySkill())->__invoke(...)))($bundled, $config);
    }

    /**
     * @param list<SkillDescriptor> $skills
     * @return list<string>
     */
    private function names(array $skills): array
    {
        return array_map(static fn (SkillDescriptor $s): string => $s->name, $skills);
    }

    private function skill(string $name): SkillDescriptor
    {
        return new SkillDescriptor($name, '/irrelevant/' . $name, 'jardis/dev-skills');
    }

    /**
     * @return list<SkillDescriptor>
     */
    private function bundled(): array
    {
        return array_map($this->skill(...), [
            'foundation-alpha',
            'process-beta',
            'adapter-cache',
            'rules-patterns',
            'rules-testing',
            'schema-authoring',
        ]);
    }
}
