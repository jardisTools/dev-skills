<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\AgentsMdUninstallAction;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Manifest\GuardManifestVersion;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Handler\Install\ReplaceExcludeBlock;
use JardisTools\DevSkills\Handler\Install\ResolveGitDir;
use JardisTools\DevSkills\Handler\Manifest\ResolveManagedFolder;
use JardisTools\DevSkills\Handler\Manifest\SelectPreviousManifest;
use JardisTools\DevSkills\Handler\Support\DetectLineEnding;
use JardisTools\DevSkills\Handler\Support\IsPathBehindLink;
use JardisTools\DevSkills\Handler\Support\RunGit;
use JardisTools\DevSkills\Handler\Uninstall\IsEmptyGeminiScaffold;
use JardisTools\DevSkills\Handler\Uninstall\RecordAgentsRemoval;
use JardisTools\DevSkills\Handler\Uninstall\RemoveAggregatedAgentsMd;
use JardisTools\DevSkills\Handler\Uninstall\RemoveClaudeMdImport;
use JardisTools\DevSkills\Handler\Uninstall\RemoveExcludeBlock;
use JardisTools\DevSkills\Handler\Uninstall\RemoveGeminiContext;
use JardisTools\DevSkills\Handler\Uninstall\RemoveManagedPaths;
use JardisTools\DevSkills\Handler\Uninstall\RemoveReviewerShells;
use JardisTools\DevSkills\Handler\Uninstall\ReverseTextEdit;
use JardisTools\DevSkills\Handler\Uninstall\StripClaudeMdImport;

final class SkillUninstaller
{
    /** @var Closure(string, ManifestReadResult, UninstallReport): list<string> */
    private readonly Closure $removeManagedPaths;

    /** @var Closure(string, string): ManifestReadResult */
    private readonly Closure $readManifest;

    /** @var Closure(string, string, Closure(): void): ?string */
    private readonly Closure $guardManifestVersion;

    /** @var Closure(string): AgentsMdUninstallAction */
    private readonly Closure $removeAggregatedAgentsMd;

    /** @var Closure(UninstallReport, AgentsMdUninstallAction): void */
    private readonly Closure $recordAgentsRemoval;

    /** @var Closure(ManifestReadResult): ?Manifest */
    private readonly Closure $selectPreviousManifest;

    private readonly UninstallAddons $uninstallAddons;

    public function __construct(?Filesystem $filesystem = null, ?UninstallAddons $uninstallAddons = null)
    {
        $fs = $filesystem ?? new Filesystem();

        $resolveManagedFolder = (new ResolveManagedFolder())->__invoke(...);
        $this->removeManagedPaths = (new RemoveManagedPaths($fs, $resolveManagedFolder))->__invoke(...);
        $this->readManifest = (new ReadManifest())->__invoke(...);
        $this->guardManifestVersion = (new GuardManifestVersion($this->readManifest))->__invoke(...);
        $this->removeAggregatedAgentsMd = (new RemoveAggregatedAgentsMd(
            (new IsPathBehindLink())->__invoke(...),
        ))->__invoke(...);
        $this->recordAgentsRemoval = (new RecordAgentsRemoval())->__invoke(...);
        $this->selectPreviousManifest = (new SelectPreviousManifest())->__invoke(...);
        $this->uninstallAddons = $uninstallAddons ?? $this->standardAddons();
    }

    /**
     * The add-ons of a normal uninstall: the mirror of the install add-ons.
     */
    private function standardAddons(): UninstallAddons
    {
        $analyze = (new AnalyzeAgentsMd())->__invoke(...);
        $detectLineEnding = (new DetectLineEnding())->__invoke(...);
        $isPathBehindLink = (new IsPathBehindLink())->__invoke(...);

        return new UninstallAddons([
            'claude-md-import' => (new RemoveClaudeMdImport(
                $analyze,
                $detectLineEnding,
                (new StripClaudeMdImport())->__invoke(...),
                $isPathBehindLink,
            ))->__invoke(...),
            'gemini-context' => (new RemoveGeminiContext(
                (new ReverseTextEdit())->__invoke(...),
                (new IsEmptyGeminiScaffold())->__invoke(...),
                $isPathBehindLink,
            ))->__invoke(...),
            'reviewer-shells' => (new RemoveReviewerShells($isPathBehindLink))->__invoke(...),
            'exclude-block' => (new RemoveExcludeBlock(
                (new ResolveGitDir((new RunGit())->__invoke(...)))->__invoke(...),
                (new ReplaceExcludeBlock($detectLineEnding))->__invoke(...),
            ))->__invoke(...),
        ]);
    }

    public function __invoke(string $projectRoot, string $pluginVersion = '0.0.0'): UninstallReport
    {
        $report = new UninstallReport();

        $report->addWarningIfAny(($this->guardManifestVersion)(
            $projectRoot,
            $pluginVersion,
            function () use ($projectRoot, $pluginVersion, $report): void {
                $this->run($projectRoot, $pluginVersion, $report);
            },
        ));

        return $report;
    }

    private function run(string $projectRoot, string $pluginVersion, UninstallReport $report): void
    {
        $read = ($this->readManifest)($projectRoot . '/' . Manifest::FILE, $pluginVersion);
        $report->addWarningIfAny($read->warning);

        // The add-ons work from the manifest as read here: removing the managed paths deletes the file.
        ($this->uninstallAddons)($projectRoot, ($this->selectPreviousManifest)($read), $report);

        foreach (($this->removeManagedPaths)($projectRoot, $read, $report) as $name) {
            $report->addRemovedSkill($name);
        }

        ($this->recordAgentsRemoval)($report, ($this->removeAggregatedAgentsMd)($projectRoot));
    }
}
