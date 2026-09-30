<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Data\SkillSelection;

final class ResolveSkillCollisions
{
    /**
     * Resolves skill name collisions: a bundle skill beats a vendor skill of
     * the same name; among vendors the alphabetically first package name wins.
     * Every loser yields a warning naming both source paths.
     *
     * @param list<SkillDescriptor> $bundled
     * @param list<SkillDescriptor> $vendor
     */
    public function __invoke(array $bundled, array $vendor): SkillSelection
    {
        $warnings = [];
        $winners = [];
        foreach ($bundled as $skill) {
            $winners[$skill->name] = $skill;
        }

        $vendorByPackage = $vendor;
        usort(
            $vendorByPackage,
            static fn (SkillDescriptor $a, SkillDescriptor $b): int => strcmp($a->sourcePackage, $b->sourcePackage),
        );

        foreach ($vendorByPackage as $skill) {
            $winner = $winners[$skill->name] ?? null;
            if ($winner === null) {
                $winners[$skill->name] = $skill;
                continue;
            }

            $warnings[] = sprintf(
                'skill "%s" from %s (%s) skipped: %s (%s) takes precedence',
                $skill->name,
                $skill->sourcePackage,
                $skill->sourceDir,
                $winner->sourcePackage,
                $winner->sourceDir,
            );
        }

        $kept = array_values(array_filter(
            [...$bundled, ...$vendor],
            static fn (SkillDescriptor $skill): bool => $winners[$skill->name] === $skill,
        ));

        return new SkillSelection($kept, $warnings);
    }
}
