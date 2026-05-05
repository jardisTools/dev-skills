<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;

final class ComputeStaleBundledSkills
{
    /**
     * Returns the names of bundled skills that exist in $all but not in $kept,
     * i.e. skills that were selected under a previous wider config and should
     * now be removed from disk.
     *
     * @param list<SkillDescriptor> $all
     * @param list<SkillDescriptor> $kept
     * @return list<string>
     */
    public function __invoke(array $all, array $kept): array
    {
        $keptNames = array_map(static fn (SkillDescriptor $s): string => $s->name, $kept);
        $allNames = array_map(static fn (SkillDescriptor $s): string => $s->name, $all);

        return array_values(array_diff($allNames, $keptNames));
    }
}
