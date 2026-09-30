<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

use Closure;
use JardisTools\DevSkills\Data\InstallReport;

/**
 * Decorator for an optional add-on step: same call signature as the wrapped
 * closure, but a failure becomes a warning in the report instead of aborting
 * the run. The core of the install never runs under this decorator.
 * Catching \Throwable is deliberate: an add-on is optional by contract, and the
 * failure is reported (never swallowed) with its message.
 */
final class WarnOnFailure
{
    /**
     * @param Closure(string, string, InstallReport): void $addon
     */
    public function __construct(
        private readonly Closure $addon,
        private readonly string $addonName,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        try {
            ($this->addon)($projectRoot, $vendorDir, $report);
        } catch (\Throwable $failure) {
            $report->addWarning(sprintf(
                'add-on "%s" failed and was skipped: %s',
                $this->addonName,
                $failure->getMessage(),
            ));
        }
    }
}
