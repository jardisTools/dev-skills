<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;

final class IsCatalogInstalled
{
    public const CATALOG_SKILL = 'packages-find-existing';

    /**
     * @param list<SkillDescriptor> $bundledSkills bundle skills selected for installation
     */
    public function __invoke(array $bundledSkills): bool
    {
        return in_array(
            self::CATALOG_SKILL,
            array_map(static fn (SkillDescriptor $s): string => $s->name, $bundledSkills),
            true,
        );
    }
}
