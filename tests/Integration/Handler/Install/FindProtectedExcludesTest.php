<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\ExpandLegacyGlobs;
use JardisTools\DevSkills\Handler\Install\FindProtectedExcludes;
use JardisTools\DevSkills\Handler\Install\IsMandatorySkill;
use PHPUnit\Framework\TestCase;

final class FindProtectedExcludesTest extends TestCase
{
    public function testExcludeHittingMandatorySkillsWarnsPerSkill(): void
    {
        $warnings = $this->find(PluginConfig::filtered([], ['foundation-*', 'process-beta', 'lib-*']));

        self::assertCount(2, $warnings);
        self::assertStringContainsString('"foundation-*"', $warnings[0]);
        self::assertStringContainsString('"foundation-alpha"', $warnings[0]);
        self::assertStringContainsString(PluginConfig::MANDATORY_NOTICE, $warnings[0]);
        self::assertStringContainsString('"process-beta"', $warnings[1]);
    }

    public function testExcludeOfOrdinarySkillsDoesNotWarn(): void
    {
        self::assertSame([], $this->find(PluginConfig::filtered([], ['lib-*'])));
    }

    public function testNoExcludeDoesNotWarn(): void
    {
        self::assertSame([], $this->find(PluginConfig::all()));
    }

    /**
     * @return list<string>
     */
    private function find(PluginConfig $config): array
    {
        $bundled = array_map(
            static fn (string $name): SkillDescriptor => new SkillDescriptor($name, '/x/' . $name, 'jardis/dev-skills'),
            ['foundation-alpha', 'process-beta', 'lib-testing'],
        );

        return (new FindProtectedExcludes(
            (new IsMandatorySkill())->__invoke(...),
            (new ExpandLegacyGlobs())->__invoke(...),
        ))($bundled, $config);
    }
}
