<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;

final class ComputeStaleBundledSkills
{
    /**
     * Returns the manifest paths of bundle skills that were installed earlier
     * but are no longer part of the selection (e.g. the config got narrower).
     * Only paths the manifest lists come back; nothing is derived from the
     * current bundle list, so folders the plugin never installed are safe.
     *
     * @param list<SkillDescriptor> $selected final selection of this run (bundle and vendor)
     * @return list<string> manifest keys
     */
    public function __invoke(?Manifest $previous, array $selected): array
    {
        if ($previous === null) {
            return [];
        }

        $selectedNames = array_map(static fn (SkillDescriptor $s): string => $s->name, $selected);

        $stale = [];
        foreach ($previous->entries as $key => $entry) {
            if ($entry['source'] !== ScanPluginSkills::SOURCE_PACKAGE) {
                continue;
            }
            if (!in_array(basename($key), $selectedNames, true)) {
                $stale[] = $key;
            }
        }

        return $stale;
    }
}
