<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Support\WarnOnUninstallFailure;

/**
 * Mirror of InstallAddons for the uninstall: every add-on runs under WarnOnUninstallFailure,
 * so a failing one ends up as a warning in the report and never stops the uninstall or the
 * add-ons after it. The add-ons get the manifest as it was read before anything was removed
 * (null when it is not healthy).
 */
final class UninstallAddons
{
    /** @var list<Closure(string, ?Manifest, UninstallReport): void> */
    private readonly array $addons;

    /**
     * @param array<string, Closure(string, ?Manifest, UninstallReport): void> $addons name => add-on step
     */
    public function __construct(array $addons = [])
    {
        $guarded = [];
        foreach ($addons as $name => $addon) {
            $guarded[] = (new WarnOnUninstallFailure($addon, (string) $name))->__invoke(...);
        }
        $this->addons = $guarded;
    }

    public function __invoke(string $projectRoot, ?Manifest $manifest, UninstallReport $report): void
    {
        foreach ($this->addons as $addon) {
            $addon($projectRoot, $manifest, $report);
        }
    }
}
