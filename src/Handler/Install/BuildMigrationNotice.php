<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\SkillDescriptor;

/**
 * The hint shown after an update that left redirect skills behind: which old
 * names now point to which new ones.
 */
final class BuildMigrationNotice
{
    /**
     * @param list<SkillDescriptor> $redirects
     * @return ?string null when there is no redirect
     */
    public function __invoke(array $redirects): ?string
    {
        if ($redirects === []) {
            return null;
        }

        $pairs = array_map(
            static fn (SkillDescriptor $r): string => $r->name . ' -> ' . (RenamedSkills::MAPPING[$r->name] ?? '?'),
            $redirects,
        );

        return sprintf(
            '%d bundle skills were renamed. Each old name is kept as a redirect skill that points to the new '
            . 'name; use the new names: %s',
            count($redirects),
            implode(', ', $pairs),
        );
    }
}
