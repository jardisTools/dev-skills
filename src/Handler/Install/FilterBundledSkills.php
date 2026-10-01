<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;

/**
 * Selects the bundle skills the config asks for. Mandatory groups are always
 * kept and cannot be excluded. Globs that match an old bundle name (up to 1.3.x)
 * also select the name the skill carries now.
 */
final class FilterBundledSkills
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
     * @return list<SkillDescriptor>
     */
    public function __invoke(array $bundled, PluginConfig $config): array
    {
        if ($config->installAll) {
            return $bundled;
        }

        $includeGlobs = ($this->expandLegacyGlobs)($config->includeGlobs);
        $excludeGlobs = ($this->expandLegacyGlobs)($config->excludeGlobs);

        $kept = [];
        foreach ($bundled as $skill) {
            if (($this->isMandatorySkill)($skill->name)) {
                $kept[] = $skill;
                continue;
            }
            if ($config->mandatoryOnly) {
                continue;
            }

            $included = $includeGlobs === [] || $this->anyMatch($skill->name, $includeGlobs);
            $excluded = $this->anyMatch($skill->name, $excludeGlobs);

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
