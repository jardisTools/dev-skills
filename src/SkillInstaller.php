<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\AgentsMdMode;
use JardisTools\DevSkills\Data\AggregateAgentsResult;
use JardisTools\DevSkills\Data\GitRulesMode;
use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Discovery\ScanAgentsFiles;
use JardisTools\DevSkills\Handler\Install\AggregateAgentsMd;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Install\BuildClaudeMdContent;
use JardisTools\DevSkills\Handler\Install\BuildExcludeLines;
use JardisTools\DevSkills\Handler\Install\BuildJsonMemberInsertion;
use JardisTools\DevSkills\Handler\Install\EnsureClaudeMdImport;
use JardisTools\DevSkills\Handler\Install\EnsureGeminiContext;
use JardisTools\DevSkills\Handler\Install\HasAgentsImport;
use JardisTools\DevSkills\Handler\Install\IsCatalogInstalled;
use JardisTools\DevSkills\Handler\Install\ListTrackedPaths;
use JardisTools\DevSkills\Handler\Install\LoadRouterText;
use JardisTools\DevSkills\Handler\Install\PlanGeminiContextEdit;
use JardisTools\DevSkills\Handler\Install\RecordAgentsAggregation;
use JardisTools\DevSkills\Handler\Install\RecordAgentsMdCreated;
use JardisTools\DevSkills\Handler\Install\RemoveManagedAgentsMd;
use JardisTools\DevSkills\Handler\Install\ReplaceExcludeBlock;
use JardisTools\DevSkills\Handler\Install\RetireAgentsMd;
use JardisTools\DevSkills\Handler\Install\ResolveGitDir;
use JardisTools\DevSkills\Handler\Install\SyncExcludeBlock;
use JardisTools\DevSkills\Handler\Manifest\ForgetSelfSetEntries;
use JardisTools\DevSkills\Handler\Manifest\GuardManifestVersion;
use JardisTools\DevSkills\Handler\Shell\BuildShellBody;
use JardisTools\DevSkills\Handler\Shell\EncodeTomlBasicString;
use JardisTools\DevSkills\Handler\Shell\EncodeTomlMultiline;
use JardisTools\DevSkills\Handler\Shell\ParseReviewerSource;
use JardisTools\DevSkills\Handler\Shell\RenderClaudeShell;
use JardisTools\DevSkills\Handler\Shell\RenderCodexShell;
use JardisTools\DevSkills\Handler\Shell\RenderCopilotShell;
use JardisTools\DevSkills\Handler\Shell\RenderCursorShell;
use JardisTools\DevSkills\Handler\Shell\RenderFrontmatterShell;
use JardisTools\DevSkills\Handler\Shell\RenderGeminiShell;
use JardisTools\DevSkills\Handler\Shell\RenderShell;
use JardisTools\DevSkills\Handler\Shell\WriteReviewerShells;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Manifest\RecordSelfSetEntry;
use JardisTools\DevSkills\Handler\Manifest\SelectPreviousManifest;
use JardisTools\DevSkills\Handler\Manifest\WriteManifest;
use JardisTools\DevSkills\Handler\Support\DetectLineEnding;
use JardisTools\DevSkills\Handler\Support\IsPathBehindLink;
use JardisTools\DevSkills\Handler\Support\RunGit;
use JardisTools\DevSkills\Handler\Support\ScanJsonArray;
use JardisTools\DevSkills\Handler\Support\ScanJsonObject;
use JardisTools\DevSkills\Handler\Support\SkipJsonValue;
use JardisTools\DevSkills\Handler\Uninstall\IsEmptyGeminiScaffold;
use JardisTools\DevSkills\Handler\Uninstall\RemoveClaudeMdImport;
use JardisTools\DevSkills\Handler\Uninstall\RemoveGeminiContext;
use JardisTools\DevSkills\Handler\Uninstall\ReverseTextEdit;
use JardisTools\DevSkills\Handler\Uninstall\StripClaudeMdImport;
use JardisTools\DevSkills\Handler\Validate\ParseSkillFrontmatter;

final class SkillInstaller
{
    private readonly InstallSkills $installSkills;

    private readonly InstallAddons $installAddons;

    private readonly string $pluginRoot;

    private readonly ?string $processDocsWarning;

    private readonly ?string $gitRulesWarning;

    private readonly ?string $profileWarning;

    private readonly ?string $agentsMdWarning;

    private readonly GitRulesMode $gitRules;

    /** @var Closure(string, string, Closure(): void): ?string */
    private readonly Closure $guardManifestVersion;

    /** @var Closure(string): list<AgentsDescriptor> */
    private readonly Closure $scanAgentsFiles;

    /** @var Closure(list<SkillDescriptor>): bool */
    private readonly Closure $isCatalogInstalled;

    /** @var Closure(string, GitRulesMode, InstallProfile): string */
    private readonly Closure $loadRouterText;

    /** @var Closure(list<AgentsDescriptor>, string, bool, string): AggregateAgentsResult */
    private readonly Closure $aggregateAgentsMd;

    /** @var Closure(InstallReport, AggregateAgentsResult): void */
    private readonly Closure $recordAgentsAggregation;

    /** @var Closure(string, string, list<SkillDescriptor>, InstallReport): void the AGENTS.md step of the mode */
    private readonly Closure $agentsMdStep;

    public function __construct(
        ?PluginConfig $config = null,
        ?Filesystem $filesystem = null,
        ?string $pluginRoot = null,
        ?InstallAddons $installAddons = null,
    ) {
        $fs = $filesystem ?? new Filesystem();
        $this->pluginRoot = $pluginRoot ?? dirname(__DIR__);
        $this->processDocsWarning = $config?->processDocsWarning;
        $this->gitRulesWarning = $config?->gitRulesWarning;
        $this->profileWarning = $config?->profileWarning;
        $this->agentsMdWarning = $config?->agentsMdWarning;
        $this->gitRules = $config->gitRules ?? GitRulesMode::Strict;

        $this->installSkills = new InstallSkills(
            $config ?? PluginConfig::all(),
            $this->pluginRoot,
            $fs,
        );
        $this->installAddons = $installAddons ?? $this->standardAddons($config ?? PluginConfig::all());
        $this->guardManifestVersion = (new GuardManifestVersion((new ReadManifest())->__invoke(...)))
            ->__invoke(...);
        $this->scanAgentsFiles = (new ScanAgentsFiles())->__invoke(...);
        $this->isCatalogInstalled = (new IsCatalogInstalled())->__invoke(...);
        $this->loadRouterText = (new LoadRouterText())->__invoke(...);
        $this->aggregateAgentsMd = (new AggregateAgentsMd(
            $fs,
            (new IsPathBehindLink())->__invoke(...),
        ))->__invoke(...);
        $this->recordAgentsAggregation = (new RecordAgentsAggregation())->__invoke(...);
        $this->agentsMdStep = match (($config ?? PluginConfig::all())->agentsMd) {
            AgentsMdMode::Aggregate => $this->aggregateAgentsMdStep(...),
            AgentsMdMode::None => static function (): void {
            },
        };
    }

    /**
     * The add-ons of a normal run: the CLAUDE.md import block, the Gemini context entry, the note
     * that the plugin created AGENTS.md, the reviewer shells and, last, the Git exclude block
     * (`process-docs`). The shells come before the exclude block, so the block knows their paths.
     */
    private function standardAddons(PluginConfig $config): InstallAddons
    {
        $recordSelfSet = (new RecordSelfSetEntry(
            (new ReadManifest())->__invoke(...),
            (new WriteManifest())->__invoke(...),
        ))->__invoke(...);
        $detectLineEnding = (new DetectLineEnding())->__invoke(...);
        $skipValue = (new SkipJsonValue())->__invoke(...);
        $isPathBehindLink = (new IsPathBehindLink())->__invoke(...);
        $runGit = (new RunGit())->__invoke(...);

        $agentsMdAddons = match ($config->agentsMd) {
            AgentsMdMode::Aggregate => $this->aggregateAddons(
                $recordSelfSet,
                $detectLineEnding,
                $skipValue,
                $isPathBehindLink,
            ),
            AgentsMdMode::None => [
                'agents-md-retire' => $this->retireAgentsMd($detectLineEnding, $isPathBehindLink),
            ],
        };

        return new InstallAddons([
            ...$agentsMdAddons,
            'reviewer-shells' => $this->reviewerShells($recordSelfSet),
            'exclude-block' => (new SyncExcludeBlock(
                $config->processDocs,
                (new ResolveGitDir($runGit))->__invoke(...),
                (new ReadManifest())->__invoke(...),
                (new BuildExcludeLines())->__invoke(...),
                (new ReplaceExcludeBlock($detectLineEnding))->__invoke(...),
                (new ListTrackedPaths($runGit))->__invoke(...),
            ))->__invoke(...),
        ]);
    }

    /**
     * The add-ons of `agents-md=aggregate`: the CLAUDE.md import block, the Gemini context entry and the
     * note that the plugin created AGENTS.md.
     *
     * @param Closure(string, string, \JardisTools\DevSkills\Data\SelfSetEntry): void $recordSelfSet
     * @param Closure(string): string $detectLineEnding
     * @param Closure(string, int): int $skipValue
     * @param Closure(string, string): bool $isPathBehindLink
     * @return array<string, Closure(string, string, InstallReport): void>
     */
    private function aggregateAddons(
        Closure $recordSelfSet,
        Closure $detectLineEnding,
        Closure $skipValue,
        Closure $isPathBehindLink,
    ): array {
        return [
            'claude-md-import' => (new EnsureClaudeMdImport(
                (new AnalyzeAgentsMd())->__invoke(...),
                (new HasAgentsImport())->__invoke(...),
                $detectLineEnding,
                (new BuildClaudeMdContent())->__invoke(...),
                $recordSelfSet,
                $isPathBehindLink,
            ))->__invoke(...),
            'gemini-context' => (new EnsureGeminiContext(
                (new PlanGeminiContextEdit(
                    $detectLineEnding,
                    (new ScanJsonObject($skipValue))->__invoke(...),
                    (new ScanJsonArray($skipValue))->__invoke(...),
                    (new BuildJsonMemberInsertion())->__invoke(...),
                ))->__invoke(...),
                $recordSelfSet,
                $isPathBehindLink,
            ))->__invoke(...),
            'agents-md-created' => (new RecordAgentsMdCreated($recordSelfSet))->__invoke(...),
        ];
    }

    /**
     * The add-on of `agents-md=none`: takes out what an earlier run left in AGENTS.md, CLAUDE.md and the
     * Gemini settings.
     *
     * @param Closure(string): string $detectLineEnding
     * @param Closure(string, string): bool $isPathBehindLink
     * @return Closure(string, string, InstallReport): void
     */
    private function retireAgentsMd(Closure $detectLineEnding, Closure $isPathBehindLink): Closure
    {
        $analyze = (new AnalyzeAgentsMd())->__invoke(...);
        $readManifest = (new ReadManifest())->__invoke(...);

        return (new RetireAgentsMd(
            $readManifest,
            (new SelectPreviousManifest())->__invoke(...),
            (new RemoveManagedAgentsMd($analyze, $isPathBehindLink))->__invoke(...),
            (new RemoveClaudeMdImport(
                $analyze,
                $detectLineEnding,
                (new StripClaudeMdImport())->__invoke(...),
                $isPathBehindLink,
            ))->__invoke(...),
            (new RemoveGeminiContext(
                (new ReverseTextEdit())->__invoke(...),
                (new IsEmptyGeminiScaffold())->__invoke(...),
                $isPathBehindLink,
            ))->__invoke(...),
            (new ForgetSelfSetEntries($readManifest, (new WriteManifest())->__invoke(...)))->__invoke(...),
        ))->__invoke(...);
    }

    /**
     * @param Closure(string, string, \JardisTools\DevSkills\Data\SelfSetEntry): void $recordSelfSet
     * @return Closure(string, string, InstallReport): void
     */
    private function reviewerShells(Closure $recordSelfSet): Closure
    {
        $encodeBasicString = (new EncodeTomlBasicString())->__invoke(...);
        $buildBody = (new BuildShellBody())->__invoke(...);
        $frontmatter = (new RenderFrontmatterShell())->__invoke(...);

        return (new WriteReviewerShells(
            $this->pluginRoot,
            (new ParseReviewerSource((new ParseSkillFrontmatter())->__invoke(...)))->__invoke(...),
            (new RenderShell(
                (new RenderClaudeShell($frontmatter, $buildBody))->__invoke(...),
                (new RenderCodexShell(
                    $encodeBasicString,
                    (new EncodeTomlMultiline($encodeBasicString))->__invoke(...),
                    $buildBody,
                ))->__invoke(...),
                (new RenderCursorShell($frontmatter, $buildBody))->__invoke(...),
                (new RenderCopilotShell($frontmatter, $buildBody))->__invoke(...),
                (new RenderGeminiShell($frontmatter, $buildBody))->__invoke(...),
            ))->__invoke(...),
            (new ReadManifest())->__invoke(...),
            $recordSelfSet,
            (new IsPathBehindLink())->__invoke(...),
        ))->__invoke(...);
    }

    public function __invoke(string $projectRoot, string $vendorDir, string $pluginVersion = '0.0.0'): InstallReport
    {
        $report = new InstallReport();
        $report->addWarningIfAny($this->processDocsWarning);
        $report->addWarningIfAny($this->gitRulesWarning);
        $report->addWarningIfAny($this->profileWarning);
        $report->addWarningIfAny($this->agentsMdWarning);

        $report->addWarningIfAny(($this->guardManifestVersion)(
            $projectRoot,
            $pluginVersion,
            function () use ($projectRoot, $vendorDir, $pluginVersion, $report): void {
                $this->run($projectRoot, $vendorDir, $pluginVersion, $report);
            },
        ));

        return $report;
    }

    private function run(string $projectRoot, string $vendorDir, string $pluginVersion, InstallReport $report): void
    {
        $keptBundled = ($this->installSkills)($projectRoot, $vendorDir, $report, $pluginVersion);

        ($this->agentsMdStep)($projectRoot, $vendorDir, $keptBundled, $report);

        ($this->installAddons)($projectRoot, $vendorDir, $report);
    }

    /**
     * @param list<SkillDescriptor> $keptBundled
     */
    private function aggregateAgentsMdStep(
        string $projectRoot,
        string $vendorDir,
        array $keptBundled,
        InstallReport $report,
    ): void {
        $result = ($this->aggregateAgentsMd)(
            ($this->scanAgentsFiles)($vendorDir),
            $projectRoot,
            ($this->isCatalogInstalled)($keptBundled),
            ($this->loadRouterText)($this->pluginRoot, $this->gitRules, $report->profile() ?? InstallProfile::Jardis),
        );
        ($this->recordAgentsAggregation)($report, $result);
    }
}
