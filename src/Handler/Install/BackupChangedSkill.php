<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\StagedSkill;

/**
 * Copies an existing skill folder to `<backupRoot>/<name>/` before it is
 * replaced, but only when there is evidence that local content would be lost:
 *
 * - folder listed in the manifest: its checksum differs from the manifest entry;
 * - folder not listed: it differs from the content about to be installed, or
 *   there is no manifest at all and the name is one of the bundle names
 *   shipped up to 1.3.x (the only case backed up without proof of a change).
 *
 * Where the copy goes is decided by FindFreeBackupDir: existing backups are
 * never overwritten or removed.
 */
final class BackupChangedSkill
{
    /**
     * @param Closure(string, string, string): string $backupFolder
     * @param Closure(string): string                 $checksumDirectory
     */
    public function __construct(
        private readonly Closure $backupFolder,
        private readonly Closure $checksumDirectory,
    ) {
    }

    /**
     * @return ?string absolute path of the created backup, null when nothing needed saving
     */
    public function __invoke(StagedSkill $staged, ?Manifest $manifest, string $backupRoot): ?string
    {
        if (!is_dir($staged->targetDir) || !$this->needsBackup($staged, $manifest)) {
            return null;
        }

        return ($this->backupFolder)($staged->targetDir, $staged->skill->name, $backupRoot);
    }

    private function needsBackup(StagedSkill $staged, ?Manifest $manifest): bool
    {
        $current = ($this->checksumDirectory)($staged->targetDir);
        $entry = $manifest?->entries[$staged->manifestKey] ?? null;

        if ($entry !== null) {
            return $entry['sha256'] !== $current;
        }

        if ($manifest === null && array_key_exists($staged->skill->name, RenamedSkills::MAPPING)) {
            return true;
        }

        return $current !== ($this->checksumDirectory)($staged->stagingDir);
    }
}
