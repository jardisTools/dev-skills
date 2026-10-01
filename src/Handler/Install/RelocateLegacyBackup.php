<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\StagedSkill;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Moves a `<name>.backup` sibling left behind by plugin versions up to 1.3.x
 * out of the skill folder into the backup root. Such a folder may hold local
 * edits, so it is moved, never deleted.
 */
final class RelocateLegacyBackup
{
    private const LEGACY_SUFFIX = '.backup';

    /**
     * @param Closure(string, string): string $findFreeBackupDir
     */
    public function __construct(
        private readonly Closure $findFreeBackupDir,
    ) {
    }

    /**
     * @return ?string absolute path the legacy backup was moved to, null when there was none
     */
    public function __invoke(StagedSkill $staged, string $backupRoot): ?string
    {
        $legacy = $staged->targetDir . self::LEGACY_SUFFIX;
        if (!is_dir($legacy)) {
            return null;
        }

        $destination = ($this->findFreeBackupDir)($backupRoot, $staged->skill->name);
        if (
            (!is_dir($backupRoot) && !@mkdir($backupRoot, 0o755, true) && !is_dir($backupRoot))
            || !@rename($legacy, $destination)
        ) {
            throw new InstallFailedException(sprintf('Could not move "%s" to "%s".', $legacy, $destination));
        }

        return $destination;
    }
}
