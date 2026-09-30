<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Data\SkillSelection;
use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;
use JardisTools\DevSkills\Handler\Discovery\ScanVendor;
use JardisTools\DevSkills\Handler\Install\ComputeStaleBundledSkills;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Handler\Install\FilterBundledSkills;
use JardisTools\DevSkills\Handler\Install\HandleConflict;
use JardisTools\DevSkills\Handler\Install\RemoveStaleBundledSkills;
use JardisTools\DevSkills\Handler\Install\ResolveSkillCollisions;
use JardisTools\DevSkills\Handler\Install\ResolveTargets;

/**
 * Sub-orchestrator for the skills part of an install run: chains discovery,
 * config filtering, stale removal, collision resolution and the copy into
 * every resolved target. Contains no logic of its own. Manifest writing is
 * chained in here in a later phase.
 */
final class InstallSkills
{
    /** @var Closure(string): list<SkillDescriptor> */
    private readonly Closure $scanVendor;

    /** @var Closure(string): list<SkillDescriptor> */
    private readonly Closure $scanPluginSkills;

    /** @var Closure(list<SkillDescriptor>, PluginConfig): list<SkillDescriptor> */
    private readonly Closure $filterBundledSkills;

    /** @var Closure(list<SkillDescriptor>, list<SkillDescriptor>): list<string> */
    private readonly Closure $computeStaleBundledSkills;

    /** @var Closure(list<string>, string): list<string> */
    private readonly Closure $removeStaleBundledSkills;

    /** @var Closure(list<SkillDescriptor>, list<SkillDescriptor>): SkillSelection */
    private readonly Closure $resolveSkillCollisions;

    /** @var Closure(string): list<string> */
    private readonly Closure $resolveTargets;

    /** @var Closure(SkillDescriptor, string): ?string */
    private readonly Closure $copySkill;

    public function __construct(
        private readonly PluginConfig $config,
        private readonly string $pluginRoot,
        Filesystem $filesystem,
    ) {
        $this->scanVendor = (new ScanVendor())->__invoke(...);
        $this->scanPluginSkills = (new ScanPluginSkills())->__invoke(...);
        $this->filterBundledSkills = (new FilterBundledSkills())->__invoke(...);
        $this->computeStaleBundledSkills = (new ComputeStaleBundledSkills())->__invoke(...);
        $this->removeStaleBundledSkills = (new RemoveStaleBundledSkills($filesystem))->__invoke(...);
        $this->resolveSkillCollisions = (new ResolveSkillCollisions())->__invoke(...);
        $this->resolveTargets = (new ResolveTargets($filesystem))->__invoke(...);
        $this->copySkill = (new CopySkill(
            $filesystem,
            (new HandleConflict($filesystem))->__invoke(...),
        ))->__invoke(...);
    }

    /**
     * @return list<SkillDescriptor> the bundle skills selected by the config
     */
    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): array
    {
        $allBundled = ($this->scanPluginSkills)($this->pluginRoot);
        $keptBundled = ($this->filterBundledSkills)($allBundled, $this->config);
        $staleNames = ($this->computeStaleBundledSkills)($allBundled, $keptBundled);

        foreach (($this->removeStaleBundledSkills)($staleNames, $projectRoot) as $removed) {
            $report->addRemovedBundledSkill($removed);
        }

        $selection = ($this->resolveSkillCollisions)($keptBundled, ($this->scanVendor)($vendorDir));
        foreach ($selection->warnings as $warning) {
            $report->addWarning($warning);
        }

        $targets = ($this->resolveTargets)($projectRoot);
        foreach ($selection->skills as $skill) {
            foreach ($targets as $target) {
                $backupPath = ($this->copySkill)($skill, $target);
                $report->addBackedUpSkillIfAny($skill->name, $backupPath);
            }
            $report->addInstalledSkill($skill->name);
        }

        return $keptBundled;
    }
}
