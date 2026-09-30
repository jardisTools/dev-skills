<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Outcome of removing deselected bundle skills: the names removed, the
 * backups made of locally changed folders before they were removed and the warnings
 * about manifest keys that were ignored.
 */
final readonly class StaleRemovalResult
{
    /**
     * @param list<string>                                    $removed unique skill names
     * @param list<array{skill: string, backupPath: string}>  $backups
     * @param list<string>                                    $warnings
     */
    public function __construct(
        public array $removed,
        public array $backups,
        public array $warnings = [],
    ) {
    }
}
