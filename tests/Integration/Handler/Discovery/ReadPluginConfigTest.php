<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Discovery;

use JardisTools\DevSkills\Data\AgentsMdMode;
use JardisTools\DevSkills\Data\GitRulesMode;
use JardisTools\DevSkills\Data\InstallProfile;
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

    public function testGitRulesDefaultToOnWhenTheKeyIsAbsent(): void
    {
        foreach ([[], ['jardis/dev-skills' => []], ['jardis/dev-skills' => ['bundled-skills' => false]]] as $extra) {
            $config = (new ReadPluginConfig())($extra);

            self::assertSame(GitRulesMode::Strict, $config->gitRules);
            self::assertNull($config->gitRulesWarning);
        }
    }

    public function testGitRulesTrueIsOn(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['git-rules' => true]]);

        self::assertSame(GitRulesMode::Strict, $config->gitRules);
        self::assertNull($config->gitRulesWarning);
    }

    public function testGitRulesFalseIsTheOptOut(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['git-rules' => false]]);

        self::assertSame(GitRulesMode::Off, $config->gitRules);
        self::assertNull($config->gitRulesWarning);
    }

    public function testGitRulesDelegatedIsTheThirdStance(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['git-rules' => 'delegated']]);

        self::assertSame(GitRulesMode::Delegated, $config->gitRules);
        self::assertNull($config->gitRulesWarning);
    }

    public function testInvalidGitRulesStayStrictAndWarn(): void
    {
        foreach (['false', 'off', 'strict', 'Delegated', 0, 1, ['delegated'], null] as $raw) {
            $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['git-rules' => $raw]]);

            self::assertSame(GitRulesMode::Strict, $config->gitRules, 'an invalid value never switches the rules off or loosens them');
            self::assertStringContainsString('git-rules', (string) $config->gitRulesWarning);
            self::assertStringContainsString('"delegated"', (string) $config->gitRulesWarning);
            self::assertStringContainsString('git-rules=true', (string) $config->gitRulesWarning);
        }
    }

    public function testGitRulesAreIndependentOfBundledSkillsAndProcessDocs(): void
    {
        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => false, 'process-docs' => 'nonsense', 'git-rules' => false],
        ]);

        self::assertSame(GitRulesMode::Off, $config->gitRules);
        self::assertNull($config->gitRulesWarning);
        self::assertTrue($config->mandatoryOnly);
        self::assertSame(ProcessDocsMode::Local, $config->processDocs);
        self::assertNotNull($config->processDocsWarning);

        $config = (new ReadPluginConfig())([
            'jardis/dev-skills' => ['bundled-skills' => ['plan-*'], 'process-docs' => 'local', 'git-rules' => 'nonsense'],
        ]);

        self::assertSame(GitRulesMode::Strict, $config->gitRules);
        self::assertNotNull($config->gitRulesWarning);
        self::assertSame(['plan-*'], $config->includeGlobs);
        self::assertSame(ProcessDocsMode::Local, $config->processDocs);
        self::assertNull($config->processDocsWarning);
    }

    public function testProfileIsAbsentByDefault(): void
    {
        foreach ([[], ['jardis/dev-skills' => ['bundled-skills' => true]]] as $extra) {
            $config = (new ReadPluginConfig())($extra);

            self::assertNull($config->profile);
            self::assertNull($config->profileWarning);
        }
    }

    public function testProfileCoreAndJardisAreRead(): void
    {
        foreach (['core' => InstallProfile::Core, 'jardis' => InstallProfile::Jardis] as $raw => $expected) {
            $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['profile' => $raw]]);

            self::assertSame($expected, $config->profile);
            self::assertNull($config->profileWarning);
        }
    }

    public function testInvalidProfileWarnsAndIsTreatedAsAbsent(): void
    {
        foreach ([['full', '"full"'], [7, 'int'], [true, 'bool'], [['core'], 'array']] as [$raw, $label]) {
            $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['profile' => $raw]]);

            self::assertNull($config->profile);
            self::assertStringContainsString('profile must be "core" or "jardis"; got ' . $label, (string) $config->profileWarning);
        }
    }

    public function testProfileIsIndependentOfBundledSkills(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['profile' => 'core', 'bundled-skills' => false]]);

        self::assertSame(InstallProfile::Core, $config->profile);
        self::assertTrue($config->mandatoryOnly);

        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['profile' => 'jardis', 'bundled-skills' => ['design-*']]]);

        self::assertSame(InstallProfile::Jardis, $config->profile);
        self::assertSame(['design-*'], $config->includeGlobs);
        self::assertSame(GitRulesMode::Strict, $config->gitRules);
    }

    public function testAgentsMdDefaultsToAggregateWithoutRootName(): void
    {
        $config = (new ReadPluginConfig())([]);

        self::assertSame(AgentsMdMode::Aggregate, $config->agentsMd);
        self::assertNull($config->agentsMdWarning);
    }

    /**
     * @return array<string, array{string, AgentsMdMode}>
     */
    public static function rootPackageNames(): array
    {
        return [
            'project' => ['acme/app', AgentsMdMode::Aggregate],
            'jardis vendor' => ['jardis/foo', AgentsMdMode::None],
            'jardiscore' => ['jardiscore/kernel', AgentsMdMode::None],
            'jardissupport' => ['jardissupport/contracts', AgentsMdMode::None],
            'jardisadapter' => ['jardisadapter/x', AgentsMdMode::None],
            'jardistools' => ['jardistools/builder', AgentsMdMode::None],
            'vendor only contains jardis' => ['notjardis/x', AgentsMdMode::Aggregate],
            'package name contains jardis' => ['acme/jardis-app', AgentsMdMode::Aggregate],
            'no root name' => ['', AgentsMdMode::Aggregate],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rootPackageNames')]
    public function testAgentsMdDefaultFollowsTheRootPackageName(string $name, AgentsMdMode $expected): void
    {
        $withoutRootKey = (new ReadPluginConfig())([], $name);
        $withOtherKeys = (new ReadPluginConfig())(
            ['jardis/dev-skills' => ['bundled-skills' => false, 'git-rules' => 'delegated', 'profile' => 'core']],
            $name,
        );

        self::assertSame($expected, $withoutRootKey->agentsMd);
        self::assertSame($expected, $withOtherKeys->agentsMd);
        self::assertNull($withoutRootKey->agentsMdWarning);
        self::assertNull($withOtherKeys->agentsMdWarning);
    }

    public function testAgentsMdExplicitValueBeatsTheDefault(): void
    {
        $none = (new ReadPluginConfig())(['jardis/dev-skills' => ['agents-md' => 'none']], 'acme/app');
        $aggregate = (new ReadPluginConfig())(['jardis/dev-skills' => ['agents-md' => 'aggregate']], 'jardiscore/kernel');

        self::assertSame(AgentsMdMode::None, $none->agentsMd);
        self::assertNull($none->agentsMdWarning);
        self::assertSame(AgentsMdMode::Aggregate, $aggregate->agentsMd);
        self::assertNull($aggregate->agentsMdWarning);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidAgentsMdValues(): array
    {
        return [
            'unknown string' => ['merge', '"merge"'],
            'bool' => [true, 'bool'],
            'null' => [null, 'null'],
            'list' => [['none'], 'array'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAgentsMdValues')]
    public function testAgentsMdInvalidValueWarnsAndFallsBackToTheDefault(mixed $raw, string $described): void
    {
        $project = (new ReadPluginConfig())(['jardis/dev-skills' => ['agents-md' => $raw]], 'acme/app');
        $package = (new ReadPluginConfig())(['jardis/dev-skills' => ['agents-md' => $raw]], 'jardiscore/kernel');

        self::assertSame(AgentsMdMode::Aggregate, $project->agentsMd);
        self::assertSame(
            'agents-md must be "aggregate" or "none"; got ' . $described . '. Treated as agents-md=aggregate.',
            $project->agentsMdWarning,
        );
        self::assertSame(AgentsMdMode::None, $package->agentsMd);
        self::assertStringEndsWith('Treated as agents-md=none.', (string) $package->agentsMdWarning);
    }

    public function testAgentsMdIsIndependentOfTheOtherKeys(): void
    {
        $config = (new ReadPluginConfig())(
            ['jardis/dev-skills' => ['agents-md' => 'none', 'bundled-skills' => ['design-*'], 'profile' => 'core']],
            'acme/app',
        );

        self::assertSame(AgentsMdMode::None, $config->agentsMd);
        self::assertSame(['design-*'], $config->includeGlobs);
        self::assertSame(InstallProfile::Core, $config->profile);
        self::assertSame(GitRulesMode::Strict, $config->gitRules);
    }

    public function testHostsDefaultToClaudeWhenTheKeyIsAbsent(): void
    {
        $withoutRoot = (new ReadPluginConfig())([]);
        $withOtherKeys = (new ReadPluginConfig())(['jardis/dev-skills' => ['profile' => 'core']]);

        self::assertSame([\JardisTools\DevSkills\Data\Host::Claude], $withoutRoot->hosts);
        self::assertSame([\JardisTools\DevSkills\Data\Host::Claude], $withOtherKeys->hosts);
        self::assertNull($withoutRoot->hostsWarning);
    }

    public function testHostsAreReadNextToTheOtherKeys(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => [
            'hosts' => ['claude', 'codex'],
            'agents-md' => 'none',
            'bundled-skills' => ['design-*'],
        ]], 'acme/app');

        self::assertSame(
            [\JardisTools\DevSkills\Data\Host::Claude, \JardisTools\DevSkills\Data\Host::Codex],
            $config->hosts,
        );
        self::assertNull($config->hostsWarning);
        self::assertSame(['design-*'], $config->includeGlobs);
        self::assertSame(AgentsMdMode::None, $config->agentsMd);
    }

    public function testInvalidHostsWarnAndFallBackToClaude(): void
    {
        $config = (new ReadPluginConfig())(['jardis/dev-skills' => ['hosts' => ['claude', 'vim']]]);

        self::assertSame([\JardisTools\DevSkills\Data\Host::Claude], $config->hosts);
        self::assertStringContainsString('entry "vim" is unknown', (string) $config->hostsWarning);
    }
}
