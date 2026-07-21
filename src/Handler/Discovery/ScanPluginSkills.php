<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\HandleConflict;

final class ScanPluginSkills
{
    /**
     * Scans the plugin repo's own `skills/` directory for cross-package
     * methodology skills (schema-authoring, platform-implementation,
     * platform-usage, rules-*).
     *
     * @return list<SkillDescriptor>
     */
    public function __invoke(string $pluginRoot): array
    {
        $skillsRoot = $pluginRoot . '/skills';
        if (!is_dir($skillsRoot)) {
            return [];
        }

        $entries = glob($skillsRoot . '/*', GLOB_ONLYDIR);
        if ($entries === false || $entries === []) {
            return [];
        }

        $skills = [];

        foreach ($entries as $dir) {
            if (str_ends_with(basename($dir), HandleConflict::BACKUP_SUFFIX)) {
                continue;
            }

            if (!is_file($dir . '/SKILL.md')) {
                continue;
            }

            $skills[] = new SkillDescriptor(
                name: basename($dir),
                sourceDir: $dir,
                sourcePackage: 'jardis/dev-skills',
            );
        }

        return $skills;
    }
}
