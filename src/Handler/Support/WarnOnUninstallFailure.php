<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\UninstallReport;

/**
 * Uninstall counterpart of WarnOnFailure: a failing optional step becomes a warning in the
 * report and never stops the uninstall or the steps after it. The failure is reported with
 * its message, never swallowed.
 */
final class WarnOnUninstallFailure
{
    /**
     * @param Closure(string, ?Manifest, UninstallReport): void $addon
     */
    public function __construct(
        private readonly Closure $addon,
        private readonly string $addonName,
    ) {
    }

    public function __invoke(string $projectRoot, ?Manifest $manifest, UninstallReport $report): void
    {
        try {
            ($this->addon)($projectRoot, $manifest, $report);
        } catch (\Throwable $failure) {
            $report->addWarningIfAny(sprintf(
                'add-on "%s" failed and was skipped: %s',
                $this->addonName,
                $failure->getMessage(),
            ));
        }
    }
}
