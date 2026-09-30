<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;

/**
 * Picks the directory for a new backup of a skill: `<backupRoot>/<name>` when
 * free, otherwise `<name>-<timestamp>` (plus a counter within the same second).
 * Never returns a path that exists, so older backups are never touched.
 */
final class FindFreeBackupDir
{
    /**
     * @param Closure(): \DateTimeImmutable $now
     */
    public function __construct(
        private readonly Closure $now,
    ) {
    }

    public function __invoke(string $backupRoot, string $name): string
    {
        $candidate = $backupRoot . '/' . $name;
        if (!file_exists($candidate)) {
            return $candidate;
        }

        $stamped = $candidate . '-' . ($this->now)()->format('Ymd\THis');
        $free = $stamped;
        for ($counter = 2; file_exists($free); $counter++) {
            $free = $stamped . '-' . $counter;
        }

        return $free;
    }
}
