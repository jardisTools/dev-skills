<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Discovery;

use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use PHPUnit\Framework\TestCase;

final class ReadPluginConfigTest extends TestCase
{
    public function testReturnsDefaultOnWhenExtraIsEmpty(): void
    {
        // Missing key → default-on: jardis-catalog + the two lifecycle skills; not none().
        $config = (new ReadPluginConfig())([]);

        self::assertFalse($config->installNone);
        self::assertFalse($config->installAll);
        self::assertSame(
            ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer'],
            $config->includeGlobs,
        );
        self::assertSame([], $config->excludeGlobs);
        self::assertNull($config->warning);
    }

    public function testReturnsDefaultOnWhenRootKeyIsMissing(): void
    {
        $config = (new ReadPluginConfig())(['other/package' => ['foo' => true]]);

        self::assertFalse($config->installNone);
        self::assertSame(
            ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer'],
            $config->includeGlobs,
        );
        self::assertNull($config->warning);
    }

    public function testReturnsDefaultOnWhenBundledSkillsKeyIsMissing(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['something-else' => 1]]);

        self::assertFalse($config->installNone);
        self::assertSame(
            ['jardis-catalog', 'jardis-start-here', 'jardis-mcp-consumer'],
            $config->includeGlobs,
        );
    }

    public function testReturnsAllWhenTrue(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => true],
        ]);

        self::assertTrue($config->installAll);
        self::assertFalse($config->installNone);
    }

    public function testReturnsNoneWhenFalse(): void
    {
        // Explicit false = user explicitly opted out of ALL bundled skills, incl. catalog.
        // This is distinct from "key missing" which now returns default-on.
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => false],
        ]);

        self::assertTrue($config->installNone);
        self::assertNull($config->warning);
    }

    public function testExcludeJardisCatalogOptOut(): void
    {
        // User can opt out of just the catalog via the exclude key.
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => [
                'bundled-skills' => ['exclude' => ['jardis-catalog']],
            ],
        ]);

        self::assertFalse($config->installNone);
        self::assertSame([], $config->includeGlobs);
        self::assertSame(['jardis-catalog'], $config->excludeGlobs);
    }

    public function testListShortcutBecomesIncludeFilter(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['plan-*', 'rules-architecture']],
        ]);

        self::assertFalse($config->installAll);
        self::assertFalse($config->installNone);
        self::assertSame(['plan-*', 'rules-architecture'], $config->includeGlobs);
        self::assertSame([], $config->excludeGlobs);
    }

    public function testObjectWithIncludeAndExclude(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => [
                'bundled-skills' => [
                    'include' => ['plan-*', 'rules-*'],
                    'exclude' => ['rules-patterns'],
                ],
            ],
        ]);

        self::assertFalse($config->installAll);
        self::assertFalse($config->installNone);
        self::assertSame(['plan-*', 'rules-*'], $config->includeGlobs);
        self::assertSame(['rules-patterns'], $config->excludeGlobs);
    }

    public function testEmptyListMeansNone(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => []],
        ]);

        self::assertTrue($config->installNone);
        self::assertNull($config->warning);
    }

    public function testEmptyObjectMeansNone(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['include' => []]],
        ]);

        self::assertTrue($config->installNone);
    }

    public function testObjectWithOnlyExcludeMeansAllExceptExcluded(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['exclude' => ['tools-*']]],
        ]);

        self::assertSame([], $config->includeGlobs);
        self::assertSame(['tools-*'], $config->excludeGlobs);
    }

    public function testInvalidScalarFallsBackToNoneWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => 42],
        ]);

        self::assertTrue($config->installNone);
        self::assertNotNull($config->warning);
        self::assertStringContainsString('int', (string) $config->warning);
    }

    public function testInvalidListEntryFallsBackToNoneWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['plan-*', 42]],
        ]);

        self::assertTrue($config->installNone);
        self::assertNotNull($config->warning);
        self::assertStringContainsString('bundled-skills[1]', (string) $config->warning);
    }

    public function testNonListIncludeFallsBackToNoneWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['include' => 'plan-*']],
        ]);

        self::assertTrue($config->installNone);
        self::assertNotNull($config->warning);
        self::assertStringContainsString('include must be a list', (string) $config->warning);
    }

    public function testInvalidIncludeEntryFallsBackToNoneWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['include' => ['plan-*', null]]],
        ]);

        self::assertTrue($config->installNone);
        self::assertNotNull($config->warning);
        self::assertStringContainsString('bundled-skills.include[1]', (string) $config->warning);
    }

    public function testNonListExcludeFallsBackToNoneWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['exclude' => 'tools-*']],
        ]);

        self::assertTrue($config->installNone);
        self::assertStringContainsString('exclude must be a list', (string) $config->warning);
    }

    public function testInvalidExcludeEntryFallsBackToNoneWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['exclude' => [1]]],
        ]);

        self::assertTrue($config->installNone);
        self::assertStringContainsString('bundled-skills.exclude[0]', (string) $config->warning);
    }
}
