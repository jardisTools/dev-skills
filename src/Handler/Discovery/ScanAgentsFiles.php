<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\AgentsDescriptor;

final class ScanAgentsFiles
{
    /**
     * Scans vendor directory for Jardis package AGENTS.md files at
     * <vendorDir>/jardis*\/*\/AGENTS.md.
     *
     * @return list<AgentsDescriptor>
     */
    public function __invoke(string $vendorDir): array
    {
        if (!is_dir($vendorDir)) {
            return [];
        }

        $descriptors = [];

        $vendors = glob($vendorDir . '/jardis*', GLOB_ONLYDIR);
        if ($vendors === false) {
            return [];
        }

        foreach ($vendors as $vendor) {
            $vendorName = basename($vendor);

            $packages = glob($vendor . '/*', GLOB_ONLYDIR);
            if ($packages === false) {
                continue;
            }

            foreach ($packages as $package) {
                $agentsFile = $package . '/AGENTS.md';
                if (!is_file($agentsFile)) {
                    continue;
                }

                $content = file_get_contents($agentsFile);
                if ($content === false) {
                    continue;
                }

                $descriptors[] = new AgentsDescriptor(
                    sourcePackage: $vendorName . '/' . basename($package),
                    content: $content,
                );
            }
        }

        return $descriptors;
    }
}
