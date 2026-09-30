<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\RenamedSkills;

/**
 * Makes globs written for the names shipped up to 1.3.x keep working: every
 * glob that matches an old bundle name additionally selects the name that
 * skill carries now. The only source is RenamedSkills::MAPPING.
 */
final class ExpandLegacyGlobs
{
    /**
     * @param list<string> $globs
     * @return list<string> the given globs followed by the new names they reach through an old name
     */
    public function __invoke(array $globs): array
    {
        $expanded = $globs;

        foreach (RenamedSkills::MAPPING as $oldName => $newName) {
            foreach ($globs as $glob) {
                if (fnmatch($glob, $oldName)) {
                    $expanded[] = $newName;
                    break;
                }
            }
        }

        return array_values(array_unique($expanded));
    }
}
