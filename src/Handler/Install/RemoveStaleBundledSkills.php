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
 * copied to the backup root first, so local edits are never lost. Keys with a
 * `..` segment are ignored (a tampered manifest must not reach outside the project).
 */
final class RemoveStaleBundledSkills
{
    /**
     * @param Closure(string): string         $checksumDirectory
     * @param Closure(string, string, string): string $backupFolder
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Closure $checksumDirectory,
        private readonly Closure $backupFolder,
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

        $realRoot = (string) realpath($projectRoot);
        $removed = [];
        $backups = [];

        foreach ($manifestKeys as $key) {
            $entry = $manifest->entries[$key] ?? null;
            $path = str_starts_with($key, '/') ? $key : $realRoot . '/' . $key;
            if ($entry === null || in_array('..', explode('/', $key), true) || !is_dir($path)) {
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

        return new StaleRemovalResult(array_values($removed), $backups);
    }
}
