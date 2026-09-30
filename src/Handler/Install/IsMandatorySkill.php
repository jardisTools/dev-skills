<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\PluginConfig;

/**
 * Tells whether a skill name belongs to a mandatory group, i.e. is installed
 * whatever `bundled-skills` selects.
 */
final class IsMandatorySkill
{
    public function __invoke(string $name): bool
    {
        foreach (PluginConfig::MANDATORY_GLOBS as $glob) {
            if (fnmatch($glob, $name)) {
                return true;
            }
        }

        return false;
    }
}
