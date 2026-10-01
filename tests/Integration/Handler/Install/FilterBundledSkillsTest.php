<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\ExpandLegacyGlobs;
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
        $kept = $this->filter($this->bundled(), PluginConfig::filtered([], ['lib-*']));

        $names = $this->names($kept);
        self::assertContains('adapter-cache', $names);
        self::assertContains('foundation-alpha', $names);
        self::assertNotContains('lib-patterns', $names);
    }

    public function testIncludeThenExcludeCombines(): void
    {
        $kept = $this->filter($this->bundled(), PluginConfig::filtered(['adapter-*', 'lib-*'], ['lib-patterns']));

        $names = $this->names($kept);
        self::assertContains('adapter-cache', $names);
        self::assertContains('lib-testing', $names);
        self::assertNotContains('lib-patterns', $names);
        self::assertNotContains('schema-tool', $names);
    }

    public function testExcludeCannotRemoveMandatoryGroups(): void
    {
        $kept = $this->filter(
            $this->bundled(),
            PluginConfig::filtered([], ['foundation-*', 'process-beta', 'lib-*']),
        );

        $names = $this->names($kept);
        self::assertContains('foundation-alpha', $names);
        self::assertContains('process-beta', $names);
        self::assertNotContains('lib-testing', $names);
    }

    public function testOldIncludeGlobSelectsTheRenamedSkills(): void
    {
        $kept = $this->filter($this->renamedBundle(), PluginConfig::filtered(['rules-*'], []));

        self::assertSame(
            ['foundation-architecture', 'foundation-patterns', 'foundation-testing', 'foundation-frontend-review'],
            $this->names($kept),
        );
    }

    public function testOldGitGlobSelectsTheGitSkillsOfTheOldNamesItMatches(): void
    {
        $kept = $this->filter($this->renamedBundle(), PluginConfig::filtered(['do-git-*'], []));

        // `do-git-*` matches do-git-branch/-commit/-push/-compliance, not do-project-git-setup.
        self::assertSame(
            [
                'foundation-architecture',
                'foundation-patterns',
                'foundation-testing',
                'foundation-frontend-review',
                'git-start-branch',
                'git-commit-change',
                'git-push-and-open-pr',
                'git-check-compliance',
            ],
            $this->names($kept),
        );
        self::assertNotContains('git-setup-repository', $this->names($kept));
    }

    public function testBroaderOldGlobSelectsAllFiveGitSkills(): void
    {
        $kept = $this->filter($this->renamedBundle(), PluginConfig::filtered(['do-*'], []));

        $names = $this->names($kept);
        foreach (
            ['git-setup-repository', 'git-start-branch', 'git-commit-change', 'git-push-and-open-pr', 'git-check-compliance'] as $git
        ) {
            self::assertContains($git, $names);
        }
    }

    public function testOldNameOfAnUnrelatedSkillDoesNotReachOtherSkills(): void
    {
        $kept = $this->filter($this->renamedBundle(), PluginConfig::filtered(['schema-authoring'], []));

        self::assertNotContains('git-start-branch', $this->names($kept));
        self::assertContains('design-draft-schema', $this->names($kept));
    }

    public function testOldExcludeGlobAlsoExcludesTheRenamedSkills(): void
    {
        $kept = $this->filter($this->renamedBundle(), PluginConfig::filtered([], ['platform-*']));

        $names = $this->names($kept);
        self::assertNotContains('generated-code-extend', $names);
        self::assertContains('git-start-branch', $names);
    }

    /**
     * @return list<SkillDescriptor>
     */
    private function renamedBundle(): array
    {
        return array_map($this->skill(...), array_values(RenamedSkills::MAPPING));
    }

    /**
     * @param list<SkillDescriptor> $bundled
     * @return list<SkillDescriptor>
     */
    private function filter(array $bundled, PluginConfig $config): array
    {
        return (new FilterBundledSkills(
            (new IsMandatorySkill())->__invoke(...),
            (new ExpandLegacyGlobs())->__invoke(...),
        ))($bundled, $config);
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
            'lib-patterns',
            'lib-testing',
            'schema-tool',
        ]);
    }
}
