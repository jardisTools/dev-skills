<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\ResolvedInstallProfile;
use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;

/**
 * Resolves the installation profile of a run, in this order:
 *
 * 1. the profile set in the config (`extra."jardis/dev-skills".profile`);
 * 2. an installation from before the profile existed (the previous manifest, real or legacy, has no
 *    profile) keeps everything: `jardis`. This profile is not recorded, so the installation stays "from
 *    before" and detection never takes the skills away from it; only the config or a fresh install does;
 * 3. detection: a package folder `<vendorDir>/jardis*` / `*` other than the plugin itself means
 *    `jardis`, otherwise `core`.
 *
 * A manifest that records a profile is not consulted: the profile is resolved anew in every run, so a
 * Jardis package that came later pulls the Jardis skills in and a removed one lets them go again.
 */
final class ResolveInstallProfile
{
    public function __invoke(PluginConfig $config, ?Manifest $previous, string $vendorDir): ResolvedInstallProfile
    {
        if ($config->profile !== null) {
            return new ResolvedInstallProfile($config->profile, $config->profile);
        }
        if ($previous !== null && $previous->profile === null) {
            return new ResolvedInstallProfile(InstallProfile::Jardis, null);
        }

        $detected = $this->hasJardisPackage($vendorDir) ? InstallProfile::Jardis : InstallProfile::Core;

        return new ResolvedInstallProfile($detected, $detected);
    }

    private function hasJardisPackage(string $vendorDir): bool
    {
        $folders = glob(rtrim($vendorDir, '/') . '/jardis*/*', GLOB_ONLYDIR);
        if ($folders === false) {
            return false;
        }

        $prefix = rtrim($vendorDir, '/') . '/';
        foreach ($folders as $folder) {
            if (substr($folder, strlen($prefix)) !== ScanPluginSkills::SOURCE_PACKAGE) {
                return true;
            }
        }

        return false;
    }
}
