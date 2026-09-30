<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;

/**
 * Warns for every mandatory bundle skill that an `exclude` glob tries to drop.
 * The exclusion has no effect (FilterBundledSkills keeps mandatory skills).
 */
final class FindProtectedExcludes
{
    /**
     * @param Closure(string): bool $isMandatorySkill
     */
    public function __construct(
        private readonly Closure $isMandatorySkill,
    ) {
    }

    /**
     * @param list<SkillDescriptor> $bundled
     * @return list<string> warnings
     */
    public function __invoke(array $bundled, PluginConfig $config): array
    {
        $warnings = [];

        foreach ($bundled as $skill) {
            if (!($this->isMandatorySkill)($skill->name)) {
                continue;
            }
            foreach ($config->excludeGlobs as $glob) {
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
