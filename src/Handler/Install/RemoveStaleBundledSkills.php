<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\StaleRemovalResult;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Removes deselected bundle skills at the paths the manifest lists (one per
 * target folder). A folder whose checksum differs from its manifest entry is
 * copied to the backup root first, so local edits are never lost. Every key is resolved
 * through the managed-folder rule; a key that is not a skill folder of the project
 * (absolute, `..`, foreign folder, symlink) is neither backed up nor removed and
 * ends up as a warning.
 */
final class RemoveStaleBundledSkills
{
    /**
     * @param Closure(string): string                 $checksumDirectory
     * @param Closure(string, string, string): string $backupFolder
     * @param Closure(string, string): ?string        $resolveManagedFolder
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Closure $checksumDirectory,
        private readonly Closure $backupFolder,
        private readonly Closure $resolveManagedFolder,
    ) {
    }

    /**
     * @param list<string> $manifestKeys paths as listed in the manifest
     */
    public function __invoke(
        array $manifestKeys,
        ?Manifest $manifest,
        string $projectRoot,
        string $backupRoot,
    ): StaleRemovalResult {
        if ($manifest === null) {
            return new StaleRemovalResult([], []);
        }

        $realRoot = realpath($projectRoot);
        if ($realRoot === false) {
            return new StaleRemovalResult([], []);
        }

        $removed = [];
        $backups = [];
        $warnings = [];

        foreach ($manifestKeys as $key) {
            $entry = $manifest->entries[$key] ?? null;
            if ($entry === null) {
                continue;
            }

            $path = ($this->resolveManagedFolder)($realRoot, $key);
            if ($path === null) {
                $warnings[] = sprintf('Ignored manifest entry "%s": not a skill folder inside the project.', $key);
                continue;
            }
            if ($path === '') {
                continue;
            }

            $name = basename($key);
            if (($this->checksumDirectory)($path) !== $entry['sha256']) {
                $backups[] = ['skill' => $name, 'backupPath' => ($this->backupFolder)($path, $name, $backupRoot)];
            }

            if (!$this->filesystem->removeDirectory($path)) {
                throw new InstallFailedException(sprintf('Could not remove deselected skill folder "%s".', $path));
            }
            $removed[$name] = $name;
        }

        return new StaleRemovalResult(array_values($removed), $backups, $warnings);
    }
}
