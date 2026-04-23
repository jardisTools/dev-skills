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
use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;
use JardisTools\DevSkills\Handler\Discovery\ScanVendor;
use JardisTools\DevSkills\Handler\Install\AggregateAgentsMd;
use JardisTools\DevSkills\Handler\Install\ComputeStaleBundledSkills;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Handler\Install\FilterBundledSkills;
use JardisTools\DevSkills\Handler\Install\HandleConflict;
use JardisTools\DevSkills\Handler\Install\RemoveStaleBundledSkills;

final class SkillInstaller
{
    /** @var Closure(string): list<SkillDescriptor> */
    private readonly Closure $scanVendor;

    /** @var Closure(string): list<SkillDescriptor> */
    private readonly Closure $scanPluginSkills;

    /** @var Closure(string): list<AgentsDescriptor> */
    private readonly Closure $scanAgentsFiles;

    /** @var Closure(list<SkillDescriptor>, PluginConfig): list<SkillDescriptor> */
    private readonly Closure $filterBundledSkills;

    /** @var Closure(list<SkillDescriptor>, list<SkillDescriptor>): list<string> */
    private readonly Closure $computeStaleBundledSkills;

    /** @var Closure(list<string>, string): list<string> */
    private readonly Closure $removeStaleBundledSkills;

    /** @var Closure(SkillDescriptor, string): ?string */
    private readonly Closure $copySkill;

    /** @var Closure(list<AgentsDescriptor>, string): AggregateAgentsResult */
    private readonly Closure $aggregateAgentsMd;

    private readonly string $pluginRoot;

    private readonly PluginConfig $config;

    public function __construct(
        ?PluginConfig $config = null,
        ?Filesystem $filesystem = null,
        ?string $pluginRoot = null,
    ) {
        $fs = $filesystem ?? new Filesystem();
        $handleConflict = (new HandleConflict($fs))->__invoke(...);

        $this->scanVendor = (new ScanVendor())->__invoke(...);
        $this->scanPluginSkills = (new ScanPluginSkills())->__invoke(...);
        $this->scanAgentsFiles = (new ScanAgentsFiles())->__invoke(...);
        $this->filterBundledSkills = (new FilterBundledSkills())->__invoke(...);
        $this->computeStaleBundledSkills = (new ComputeStaleBundledSkills())->__invoke(...);
        $this->removeStaleBundledSkills = (new RemoveStaleBundledSkills($fs))->__invoke(...);
        $this->copySkill = (new CopySkill($fs, $handleConflict))->__invoke(...);
        $this->aggregateAgentsMd = (new AggregateAgentsMd($fs))->__invoke(...);
        $this->pluginRoot = $pluginRoot ?? dirname(__DIR__);
        $this->config = $config ?? PluginConfig::none();
    }

    public function __invoke(string $projectRoot, string $vendorDir): InstallReport
    {
        $report = new InstallReport();

        $allBundled = ($this->scanPluginSkills)($this->pluginRoot);
        $keptBundled = ($this->filterBundledSkills)($allBundled, $this->config);
        $staleNames = ($this->computeStaleBundledSkills)($allBundled, $keptBundled);

        foreach (($this->removeStaleBundledSkills)($staleNames, $projectRoot) as $removed) {
            $report->addRemovedBundledSkill($removed);
        }

        $skills = [
            ...$keptBundled,
            ...($this->scanVendor)($vendorDir),
        ];

        foreach ($skills as $skill) {
            $backupPath = ($this->copySkill)($skill, $projectRoot);
            $report->addInstalledSkill($skill->name);
            if ($backupPath !== null) {
                $report->addBackedUpSkill($skill->name, $backupPath);
            }
        }

        $agents = ($this->scanAgentsFiles)($vendorDir);
        $result = ($this->aggregateAgentsMd)($agents, $projectRoot);
        $report->setAgentsFilesAggregated($result->aggregatedCount);
        if ($result->backupPath !== null) {
            $report->setAgentsMdBackupPath($result->backupPath);
        }

        return $report;
    }
}
