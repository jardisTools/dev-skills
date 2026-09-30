<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Data\SkillSelection;
use JardisTools\DevSkills\Data\StagedSkill;
use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;
use JardisTools\DevSkills\Handler\Discovery\ScanVendor;
use JardisTools\DevSkills\Handler\Install\BackupChangedSkill;
use JardisTools\DevSkills\Handler\Install\BuildManifestEntries;
use JardisTools\DevSkills\Handler\Install\CommitStagedSkills;
use JardisTools\DevSkills\Handler\Install\ComputeStaleBundledSkills;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Handler\Install\FilterBundledSkills;
use JardisTools\DevSkills\Handler\Install\FindFreeBackupDir;
use JardisTools\DevSkills\Handler\Install\RelocateLegacyBackup;
use JardisTools\DevSkills\Handler\Install\RemoveStaleBundledSkills;
use JardisTools\DevSkills\Handler\Install\ResolveSkillCollisions;
use JardisTools\DevSkills\Handler\Install\ResolveTargets;
use JardisTools\DevSkills\Handler\Install\StageSkills;
use JardisTools\DevSkills\Handler\Manifest\ChecksumDirectory;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Handler\Manifest\WriteManifest;

/**
 * Sub-orchestrator for the skills part of an install run: chains discovery,
 * config filtering, stale removal, collision resolution, staging, backup of
 * locally changed folders, the swap into every resolved target and finally the
 * manifest. Contains no logic of its own.
 */
final class InstallSkills
{
    private const BACKUP_DIR = '.claude/.jardis-backup';

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

    /** @var Closure(string, string): ManifestReadResult */
    private readonly Closure $readManifest;

    /** @var Closure(list<SkillDescriptor>, list<string>, string): list<StagedSkill> */
    private readonly Closure $stageSkills;

    /** @var Closure(StagedSkill, ?Manifest, string): ?string */
    private readonly Closure $backupChangedSkill;

    /** @var Closure(StagedSkill, string): ?string */
    private readonly Closure $relocateLegacyBackup;

    /** @var Closure(list<StagedSkill>): void */
    private readonly Closure $commitStagedSkills;

    /** @var Closure(?Manifest, list<StagedSkill>, string, string): Manifest */
    private readonly Closure $buildManifestEntries;

    /** @var Closure(string, Manifest): void */
    private readonly Closure $writeManifest;

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
        $copySkill = (new CopySkill($filesystem))->__invoke(...);
        $checksum = (new ChecksumDirectory())->__invoke(...);
        $this->readManifest = (new ReadManifest())->__invoke(...);
        $this->stageSkills = (new StageSkills($filesystem, $copySkill))->__invoke(...);
        $findFreeBackupDir = (new FindFreeBackupDir(
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
        ))->__invoke(...);
        $this->relocateLegacyBackup = (new RelocateLegacyBackup($findFreeBackupDir))->__invoke(...);
        $this->backupChangedSkill = (new BackupChangedSkill($copySkill, $checksum, $findFreeBackupDir))->__invoke(...);
        $this->commitStagedSkills = (new CommitStagedSkills($filesystem))->__invoke(...);
        $this->buildManifestEntries = (new BuildManifestEntries($checksum))->__invoke(...);
        $this->writeManifest = (new WriteManifest())->__invoke(...);
    }

    /**
     * @return list<SkillDescriptor> the bundle skills selected by the config
     */
    public function __invoke(
        string $projectRoot,
        string $vendorDir,
        InstallReport $report,
        string $pluginVersion,
    ): array {
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

        $manifestPath = $projectRoot . '/' . Manifest::FILE;
        $read = ($this->readManifest)($manifestPath, $pluginVersion);
        if ($read->warning !== '') {
            $report->addWarning($read->warning);
        }
        $previous = $read->state === ManifestState::Healthy ? $read->manifest : null;

        $targets = ($this->resolveTargets)($projectRoot);
        $staged = ($this->stageSkills)($selection->skills, $targets, $projectRoot);

        $backupRoot = $projectRoot . '/' . self::BACKUP_DIR;
        foreach ($staged as $item) {
            $name = $item->skill->name;
            $report->addBackedUpSkillIfAny($name, ($this->relocateLegacyBackup)($item, $backupRoot));
            $report->addBackedUpSkillIfAny($name, ($this->backupChangedSkill)($item, $previous, $backupRoot));
        }

        ($this->commitStagedSkills)($staged);
        ($this->writeManifest)(
            $manifestPath,
            ($this->buildManifestEntries)($previous, $staged, $projectRoot, $pluginVersion),
        );

        foreach ($selection->skills as $skill) {
            $report->addInstalledSkill($skill->name);
        }

        return $keptBundled;
    }
}
