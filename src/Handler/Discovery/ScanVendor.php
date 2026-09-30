<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\SkillDescriptor;

final class ScanVendor
{
    /**
     * Scans vendor directory for Jardis package skills at
     * <vendorDir>/jardis*\/*\/.claude/skills/<skill-name>/SKILL.md.
     *
     * @return list<SkillDescriptor>
     */
    public function __invoke(string $vendorDir): array
    {
        if (!is_dir($vendorDir)) {
            return [];
        }

        $skills = [];

        foreach ($this->listDirectories($vendorDir, 'jardis*') as $vendor) {
            $vendorName = basename($vendor);

            foreach ($this->listDirectories($vendor) as $package) {
                $skillsRoot = $package . '/.claude/skills';
                if (!is_dir($skillsRoot)) {
                    continue;
                }

                $sourcePackage = $vendorName . '/' . basename($package);

                foreach ($this->listDirectories($skillsRoot) as $skillDir) {
                    if (!is_file($skillDir . '/SKILL.md')) {
                        continue;
                    }

                    $skills[] = new SkillDescriptor(
                        name: basename($skillDir),
                        sourceDir: $skillDir,
                        sourcePackage: $sourcePackage,
                    );
                }
            }
        }

        return $skills;
    }

    /**
     * @return list<string>
     */
    private function listDirectories(string $path, string $pattern = '*'): array
    {
        $matches = glob($path . '/' . $pattern, GLOB_ONLYDIR);
        if ($matches === false) {
            return [];
        }

        /** @var list<string> $matches */
        return $matches;
    }
}
