<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Copies a folder to `<backupRoot>/<name>` (or a timestamped sibling, see
 * FindFreeBackupDir). Existing backups are never overwritten.
 */
final class BackupFolder
{
    /**
     * @param Closure(SkillDescriptor, string): void $copySkill
     * @param Closure(string, string): string        $findFreeBackupDir
     */
    public function __construct(
        private readonly Closure $copySkill,
        private readonly Closure $findFreeBackupDir,
    ) {
    }

    /**
     * @return string absolute path of the created backup
     */
    public function __invoke(string $folder, string $name, string $backupRoot): string
    {
        $backupDir = ($this->findFreeBackupDir)($backupRoot, $name);

        try {
            ($this->copySkill)(new SkillDescriptor($name, $folder, 'local-backup'), $backupDir);
        } catch (\Throwable $failure) {
            throw new InstallFailedException(
                sprintf('Could not back up "%s" to "%s": %s', $folder, $backupDir, $failure->getMessage()),
                0,
                $failure,
            );
        }

        return $backupDir;
    }
}
