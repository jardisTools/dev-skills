<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\SkillDescriptor;

/**
 * Selects the bundle skills the config asks for. Mandatory groups are always
 * kept and cannot be excluded.
 */
final class FilterBundledSkills
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
     * @return list<SkillDescriptor>
     */
    public function __invoke(array $bundled, PluginConfig $config): array
    {
        if ($config->installAll) {
            return $bundled;
        }

        $kept = [];
        foreach ($bundled as $skill) {
            if (($this->isMandatorySkill)($skill->name)) {
                $kept[] = $skill;
                continue;
            }
            if ($config->mandatoryOnly) {
                continue;
            }

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
