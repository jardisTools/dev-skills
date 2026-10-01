<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\SkillDescriptor;

final class ScanPluginSkills
{
    public const SOURCE_PACKAGE = 'jardis/dev-skills';

    /**
     * Scans the plugin repo's own `skills/` directory for cross-package
     * methodology skills (design-draft-schema, generated-code-*,
     * foundation-*).
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
            if (!is_file($dir . '/SKILL.md')) {
                continue;
            }

            $skills[] = new SkillDescriptor(
                name: basename($dir),
                sourceDir: $dir,
                sourcePackage: self::SOURCE_PACKAGE,
            );
        }

        return $skills;
    }
}
