<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Manifest\ResolvePluginVersion;

/**
 * Add-on of an install run whose `hosts` does not list `gemini`: takes out the entry an earlier run set in
 * `.gemini/settings.json` (and the file, when the plugin created it and only the scaffold is left) and
 * forgets the manifest note. Without that note nothing happens, so a foreign file is never touched and a
 * second run changes nothing.
 */
final class RetireGeminiContext
{
    /**
     * @param Closure(string, string): ManifestReadResult        $readManifest
     * @param Closure(ManifestReadResult): ?Manifest             $selectPreviousManifest
     * @param Closure(string, ?Manifest, UninstallReport): void  $removeGeminiContext
     * @param Closure(string, list<string>): void                $forgetSelfSetEntries
     */
    public function __construct(
        private readonly Closure $readManifest,
        private readonly Closure $selectPreviousManifest,
        private readonly Closure $removeGeminiContext,
        private readonly Closure $forgetSelfSetEntries,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $manifest = ($this->selectPreviousManifest)(
            ($this->readManifest)($projectRoot . '/' . Manifest::FILE, ResolvePluginVersion::DEV_VERSION),
        );
        if ($manifest === null || !isset($manifest->selfSet[EnsureGeminiContext::FILE])) {
            return;
        }

        $reversal = new UninstallReport();
        ($this->removeGeminiContext)($projectRoot, $manifest, $reversal);
        foreach ($reversal->warnings() as $warning) {
            $report->addWarning($warning);
        }

        ($this->forgetSelfSetEntries)($projectRoot, [EnsureGeminiContext::FILE]);
    }
}
