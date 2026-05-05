<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;

final class FilterBundledSkills
{
    /**
     * @param list<SkillDescriptor> $bundled
     * @return list<SkillDescriptor>
     */
    public function __invoke(array $bundled, PluginConfig $config): array
    {
        if ($config->installNone) {
            return [];
        }
        if ($config->installAll) {
            return $bundled;
        }

        $kept = [];
        foreach ($bundled as $skill) {
            $included = $config->includeGlobs === []
                || $this->anyMatch($skill->name, $config->includeGlobs);
            $excluded = $this->anyMatch($skill->name, $config->excludeGlobs);

            if ($included && !$excluded) {
                $kept[] = $skill;
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $globs
     */
    private function anyMatch(string $name, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $name)) {
                return true;
            }
        }

        return false;
    }
}
