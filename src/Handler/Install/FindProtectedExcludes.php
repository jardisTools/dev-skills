<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;

/**
 * Warns for every mandatory bundle skill that an `exclude` glob tries to drop.
 * The exclusion has no effect (FilterBundledSkills keeps mandatory skills). A glob on an old
 * bundle name counts as well, because it also reaches the new name.
 */
final class FindProtectedExcludes
{
    /**
     * @param Closure(string): bool               $isMandatorySkill
     * @param Closure(list<string>): list<string> $expandLegacyGlobs
     */
    public function __construct(
        private readonly Closure $isMandatorySkill,
        private readonly Closure $expandLegacyGlobs,
    ) {
    }

    /**
     * @param list<SkillDescriptor> $bundled
     * @return list<string> warnings
     */
    public function __invoke(array $bundled, PluginConfig $config): array
    {
        $warnings = [];
        $excludeGlobs = ($this->expandLegacyGlobs)($config->excludeGlobs);

        foreach ($bundled as $skill) {
            if (!($this->isMandatorySkill)($skill->name)) {
                continue;
            }
            foreach ($excludeGlobs as $glob) {
                if (fnmatch($glob, $skill->name)) {
                    $warnings[] = sprintf(
                        'bundled-skills.exclude "%s" has no effect on "%s": %s',
                        $glob,
                        $skill->name,
                        PluginConfig::MANDATORY_NOTICE,
                    );
                    break;
                }
            }
        }

        return $warnings;
    }
}
