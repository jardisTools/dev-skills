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
 * Add-on of an install run with `agents-md=none`: the project is itself a Jardis package, so nothing the
 * plugin manages may stand in its AGENTS.md. It takes out what an earlier run (or an earlier release)
 * left there — the managed block in AGENTS.md, the import block in CLAUDE.md, the entry in
 * `.gemini/settings.json` — and forgets the notes about these files in the manifest. Without any of it,
 * nothing happens, so a second run changes nothing. Chains the reversal steps of the uninstall.
 */
final class RetireAgentsMd
{
    /**
     * @param Closure(string, string): ManifestReadResult                  $readManifest
     * @param Closure(ManifestReadResult): ?Manifest                       $selectPreviousManifest
     * @param Closure(string, Manifest): ?string                           $removeAgentsMd
     * @param Closure(string, ?Manifest, UninstallReport): void            $removeClaudeMdImport
     * @param Closure(string, ?Manifest, UninstallReport): void            $removeGeminiContext
     * @param Closure(string, list<string>): void                          $forgetSelfSetEntries
     */
    public function __construct(
        private readonly Closure $readManifest,
        private readonly Closure $selectPreviousManifest,
        private readonly Closure $removeAgentsMd,
        private readonly Closure $removeClaudeMdImport,
        private readonly Closure $removeGeminiContext,
        private readonly Closure $forgetSelfSetEntries,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $manifest = ($this->selectPreviousManifest)(
            ($this->readManifest)($projectRoot . '/' . Manifest::FILE, ResolvePluginVersion::DEV_VERSION),
        ) ?? new Manifest(Manifest::SCHEMA_VERSION, ResolvePluginVersion::DEV_VERSION);

        $reversal = new UninstallReport();
        $report->addWarningIfAny(($this->removeAgentsMd)($projectRoot, $manifest));
        ($this->removeClaudeMdImport)($projectRoot, $manifest, $reversal);
        ($this->removeGeminiContext)($projectRoot, $manifest, $reversal);
        foreach ($reversal->warnings() as $warning) {
            $report->addWarning($warning);
        }

        ($this->forgetSelfSetEntries)($projectRoot, [
            RemoveManagedAgentsMd::FILE,
            EnsureClaudeMdImport::FILE,
            EnsureGeminiContext::FILE,
        ]);
    }
}
