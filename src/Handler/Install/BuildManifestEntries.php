<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\InstallProfile;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\StagedSkill;

/**
 * Builds the manifest for a finished install: one entry per installed target
 * with the checksum of the folder as it now is on disk, plus the entries of
 * the previous manifest whose folders still exist. What the plugin set in foreign
 * files (`selfSet`) is carried over unchanged: a later run must not forget it. The profile of this run is recorded.
 */
final class BuildManifestEntries
{
    /**
     * @param Closure(string): string          $checksumDirectory
     * @param Closure(string, string): ?string $resolveManagedFolder
     */
    public function __construct(
        private readonly Closure $checksumDirectory,
        private readonly Closure $resolveManagedFolder,
    ) {
    }

    /**
     * @param list<StagedSkill> $installed already swapped into their targets
     */
    public function __invoke(
        ?Manifest $previous,
        array $installed,
        string $projectRoot,
        string $pluginVersion,
        ?InstallProfile $profile = null,
    ): Manifest {
        $realRoot = (string) realpath($projectRoot);
        $entries = [];

        foreach ($previous->entries ?? [] as $key => $entry) {
            $folder = ($this->resolveManagedFolder)($realRoot, (string) $key);
            if ($folder !== null && $folder !== '') {
                $entries[$key] = $entry;
            }
        }

        foreach ($installed as $item) {
            $entries[$item->manifestKey] = [
                'source' => $item->skill->sourcePackage,
                'sha256' => ($this->checksumDirectory)($item->targetDir),
            ];
        }

        return new Manifest(Manifest::SCHEMA_VERSION, $pluginVersion, $entries, $previous->selfSet ?? [], $profile);
    }
}
