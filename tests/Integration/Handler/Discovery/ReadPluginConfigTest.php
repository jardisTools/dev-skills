<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Discovery;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\ProcessDocsMode;
use JardisTools\DevSkills\Handler\Discovery\ReadPluginConfig;
use PHPUnit\Framework\TestCase;

/**
 * One test per row of the R1b config table, plus the malformed-input cases.
 */
final class ReadPluginConfigTest extends TestCase
{
    private const MANDATORY_WARNING = 'bundled-skills=false: mandatory groups foundation-*/process-* are always installed';

    public function testAbsentKeyInstallsAll(): void
    {
        $config = (new ReadPluginConfig())([]);

        self::assertTrue($config->installAll);
        self::assertFalse($config->mandatoryOnly);
        self::assertNull($config->warning);
    }

    public function testAbsentRootKeyInstallsAll(): void
    {
        $config = (new ReadPluginConfig())(['other/package' => ['foo' => true]]);

        self::assertTrue($config->installAll);
        self::assertNull($config->warning);
    }

    public function testAbsentBundledSkillsKeyInstallsAll(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['something-else' => 1]]);

        self::assertTrue($config->installAll);
        self::assertNull($config->warning);
    }

    public function testTrueInstallsAll(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => true]]);

        self::assertTrue($config->installAll);
        self::assertFalse($config->mandatoryOnly);
        self::assertNull($config->warning);
    }

    public function testFalseKeepsOnlyMandatoryGroupsWithWarning(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => false]]);

        self::assertFalse($config->installAll);
        self::assertTrue($config->mandatoryOnly);
        self::assertSame(self::MANDATORY_WARNING, $config->warning);
    }

    public function testEmptyListBehavesLikeFalse(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => []]]);

        self::assertTrue($config->mandatoryOnly);
        self::assertSame(self::MANDATORY_WARNING, $config->warning);
    }

    public function testEmptyIncludeBehavesLikeFalse(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['include' => []]],
        ]);

        self::assertTrue($config->mandatoryOnly);
        self::assertSame(self::MANDATORY_WARNING, $config->warning);
    }

    public function testListBecomesIncludeFilter(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['plan-*', 'rules-architecture']],
        ]);

        self::assertFalse($config->installAll);
        self::assertFalse($config->mandatoryOnly);
        self::assertSame(['plan-*', 'rules-architecture'], $config->includeGlobs);
        self::assertSame([], $config->excludeGlobs);
        self::assertNull($config->warning);
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
        self::assertFalse($config->mandatoryOnly);
        self::assertSame(['plan-*', 'rules-*'], $config->includeGlobs);
        self::assertSame(['rules-patterns'], $config->excludeGlobs);
        self::assertNull($config->warning);
    }

    public function testObjectWithOnlyExcludeMeansAllExceptExcluded(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['exclude' => ['tools-*']]],
        ]);

        self::assertSame([], $config->includeGlobs);
        self::assertSame(['tools-*'], $config->excludeGlobs);
    }

    public function testInvalidScalarBehavesLikeFalseWithWarning(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['bundled-skills' => 42]]);

        self::assertTrue($config->mandatoryOnly);
        self::assertStringContainsString('int', (string) $config->warning);
        self::assertStringContainsString(PluginConfig::MANDATORY_NOTICE, (string) $config->warning);
    }

    public function testInvalidListEntryBehavesLikeFalseWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['plan-*', 42]],
        ]);

        self::assertTrue($config->mandatoryOnly);
        self::assertStringContainsString('bundled-skills[1]', (string) $config->warning);
    }

    public function testNonListIncludeBehavesLikeFalseWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['include' => 'plan-*']],
        ]);

        self::assertTrue($config->mandatoryOnly);
        self::assertStringContainsString('include must be a list', (string) $config->warning);
    }

    public function testInvalidIncludeEntryBehavesLikeFalseWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['include' => ['plan-*', null]]],
        ]);

        self::assertTrue($config->mandatoryOnly);
        self::assertStringContainsString('bundled-skills.include[1]', (string) $config->warning);
    }

    public function testNonListExcludeBehavesLikeFalseWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['exclude' => 'tools-*']],
        ]);

        self::assertTrue($config->mandatoryOnly);
        self::assertStringContainsString('exclude must be a list', (string) $config->warning);
    }

    public function testInvalidExcludeEntryBehavesLikeFalseWithWarning(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['exclude' => [1]]],
        ]);

        self::assertTrue($config->mandatoryOnly);
        self::assertStringContainsString('bundled-skills.exclude[0]', (string) $config->warning);
    }

    public function testProcessDocsDefaultsToCommitted(): void
    {
        foreach ([[], ['jardis/dev-skills' => []], ['jardis/dev-skills' => ['bundled-skills' => false]]] as $extra) {
            $config = (new ReadPluginConfig())($extra);

            self::assertSame(ProcessDocsMode::Committed, $config->processDocs);
            self::assertNull($config->processDocsWarning);
        }
    }

    public function testProcessDocsLocalIsRead(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['process-docs' => 'local']]);

        self::assertSame(ProcessDocsMode::Local, $config->processDocs);
        self::assertNull($config->processDocsWarning);
        self::assertTrue($config->installAll, 'independent of bundled-skills');
    }

    public function testProcessDocsIsIndependentOfBundledSkills(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['plan-*'], 'process-docs' => 'committed'],
        ]);

        self::assertSame(ProcessDocsMode::Committed, $config->processDocs);
        self::assertSame(['plan-*'], $config->includeGlobs);
    }

    public function testInvalidProcessDocsWarnsAndFallsBackToLocal(): void
    {
        foreach (['public', 42, true, ['local'], null] as $raw) {
            $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['process-docs' => $raw]]);

            self::assertSame(ProcessDocsMode::Local, $config->processDocs);
            self::assertStringContainsString('process-docs', (string) $config->processDocsWarning);
            self::assertStringContainsString('local', (string) $config->processDocsWarning);
        }
    }

    public function testInvalidProcessDocsDoesNotDisturbBundledSkills(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => false, 'process-docs' => 'nonsense'],
        ]);

        self::assertTrue($config->mandatoryOnly);
        self::assertSame(self::MANDATORY_WARNING, $config->warning);
        self::assertSame(ProcessDocsMode::Local, $config->processDocs);
    }
}
