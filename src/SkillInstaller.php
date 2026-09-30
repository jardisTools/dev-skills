<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\AggregateAgentsResult;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Discovery\ScanAgentsFiles;
use JardisTools\DevSkills\Handler\Install\AggregateAgentsMd;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Install\BuildClaudeMdContent;
use JardisTools\DevSkills\Handler\Install\BuildJsonMemberInsertion;
use JardisTools\DevSkills\Handler\Install\EnsureClaudeMdImport;
use JardisTools\DevSkills\Handler\Install\EnsureGeminiContext;
use JardisTools\DevSkills\Handler\Install\HasAgentsImport;
use JardisTools\DevSkills\Handler\Install\IsCatalogInstalled;
use JardisTools\DevSkills\Handler\Install\LoadRouterText;
use JardisTools\DevSkills\Handler\Install\PlanGeminiContextEdit;
use JardisTools\DevSkills\Handler\Install\RecordAgentsAggregation;
use JardisTools\DevSkills\Handler\Manifest\GuardManifestVersion;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Manifest\RecordSelfSetEntry;
use JardisTools\DevSkills\Handler\Manifest\WriteManifest;
use JardisTools\DevSkills\Handler\Support\DetectLineEnding;
use JardisTools\DevSkills\Handler\Support\IsLinkLeavingProject;
use JardisTools\DevSkills\Handler\Support\ScanJsonArray;
use JardisTools\DevSkills\Handler\Support\ScanJsonObject;
use JardisTools\DevSkills\Handler\Support\SkipJsonValue;

final class SkillInstaller
{
    private readonly InstallSkills $installSkills;

    private readonly InstallAddons $installAddons;

    private readonly string $pluginRoot;

    /** @var Closure(string, string, Closure(): void): ?string */
    private readonly Closure $guardManifestVersion;

    /** @var Closure(string): list<AgentsDescriptor> */
    private readonly Closure $scanAgentsFiles;

    /** @var Closure(list<SkillDescriptor>): bool */
    private readonly Closure $isCatalogInstalled;

    /** @var Closure(string): string */
    private readonly Closure $loadRouterText;

    /** @var Closure(list<AgentsDescriptor>, string, bool, string): AggregateAgentsResult */
    private readonly Closure $aggregateAgentsMd;

    /** @var Closure(InstallReport, AggregateAgentsResult): void */
    private readonly Closure $recordAgentsAggregation;

    public function __construct(
        ?PluginConfig $config = null,
        ?Filesystem $filesystem = null,
        ?string $pluginRoot = null,
        ?InstallAddons $installAddons = null,
    ) {
        $fs = $filesystem ?? new Filesystem();
        $this->pluginRoot = $pluginRoot ?? dirname(__DIR__);

        $this->installSkills = new InstallSkills(
            $config ?? PluginConfig::all(),
            $this->pluginRoot,
            $fs,
        );
        $this->installAddons = $installAddons ?? $this->standardAddons();
        $this->guardManifestVersion = (new GuardManifestVersion((new ReadManifest())->__invoke(...)))
            ->__invoke(...);
        $this->scanAgentsFiles = (new ScanAgentsFiles())->__invoke(...);
        $this->isCatalogInstalled = (new IsCatalogInstalled())->__invoke(...);
        $this->loadRouterText = (new LoadRouterText())->__invoke(...);
        $this->aggregateAgentsMd = (new AggregateAgentsMd($fs))->__invoke(...);
        $this->recordAgentsAggregation = (new RecordAgentsAggregation())->__invoke(...);
    }

    /**
     * The add-ons of a normal run: the CLAUDE.md import block and the Gemini context entry.
     */
    private function standardAddons(): InstallAddons
    {
        $recordSelfSet = (new RecordSelfSetEntry(
            (new ReadManifest())->__invoke(...),
            (new WriteManifest())->__invoke(...),
        ))->__invoke(...);
        $detectLineEnding = (new DetectLineEnding())->__invoke(...);
        $skipValue = (new SkipJsonValue())->__invoke(...);
        $isLinkLeavingProject = (new IsLinkLeavingProject())->__invoke(...);

        return new InstallAddons([
            'claude-md-import' => (new EnsureClaudeMdImport(
                (new AnalyzeAgentsMd())->__invoke(...),
                (new HasAgentsImport())->__invoke(...),
                $detectLineEnding,
                (new BuildClaudeMdContent())->__invoke(...),
                $recordSelfSet,
                $isLinkLeavingProject,
            ))->__invoke(...),
            'gemini-context' => (new EnsureGeminiContext(
                (new PlanGeminiContextEdit(
                    $detectLineEnding,
                    (new ScanJsonObject($skipValue))->__invoke(...),
                    (new ScanJsonArray($skipValue))->__invoke(...),
                    (new BuildJsonMemberInsertion())->__invoke(...),
                ))->__invoke(...),
                $recordSelfSet,
                $isLinkLeavingProject,
            ))->__invoke(...),
        ]);
    }

    public function __invoke(string $projectRoot, string $vendorDir, string $pluginVersion = '0.0.0'): InstallReport
    {
        $report = new InstallReport();

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

        $result = ($this->aggregateAgentsMd)(
            ($this->scanAgentsFiles)($vendorDir),
            $projectRoot,
            ($this->isCatalogInstalled)($keptBundled),
            ($this->loadRouterText)($this->pluginRoot),
        );
        ($this->recordAgentsAggregation)($report, $result);

        ($this->installAddons)($projectRoot, $vendorDir, $report);
    }
}
