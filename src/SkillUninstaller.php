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
use JardisTools\DevSkills\Handler\Uninstall\RemoveAggregatedAgentsMd;
use JardisTools\DevSkills\Handler\Uninstall\RemoveManagedPaths;

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

    public function __construct(?Filesystem $filesystem = null)
    {
        $fs = $filesystem ?? new Filesystem();

        $this->removeManagedPaths = (new RemoveManagedPaths($fs))->__invoke(...);
        $this->readManifest = (new ReadManifest())->__invoke(...);
        $this->guardManifestVersion = (new GuardManifestVersion($this->readManifest))->__invoke(...);
        $this->removeAggregatedAgentsMd = (new RemoveAggregatedAgentsMd())->__invoke(...);
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

        foreach (($this->removeManagedPaths)($projectRoot, $read, $report) as $name) {
            $report->addRemovedSkill($name);
        }

        $report->setAgentsMdAction(($this->removeAggregatedAgentsMd)($projectRoot));
    }
}
