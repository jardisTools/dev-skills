<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Handler\Support\WarnOnFailure;

/**
 * Sub-orchestrator for the optional add-ons of an install run (the standard list is
 * built in SkillInstaller). Every add-on runs under WarnOnFailure: a failing add-on ends up as a
 * warning in the report and never stops the run or the add-ons after it.
 */
final class InstallAddons
{
    /** @var list<Closure(string, string, InstallReport): void> */
    private readonly array $addons;

    /**
     * @param array<string, Closure(string, string, InstallReport): void> $addons name => add-on step
     */
    public function __construct(array $addons = [])
    {
        $guarded = [];
        foreach ($addons as $name => $addon) {
            $guarded[] = (new WarnOnFailure($addon, (string) $name))->__invoke(...);
        }
        $this->addons = $guarded;
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        foreach ($this->addons as $addon) {
            $addon($projectRoot, $vendorDir, $report);
        }
    }
}
