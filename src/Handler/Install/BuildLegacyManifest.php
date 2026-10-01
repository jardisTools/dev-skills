<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;

/**
 * Stands in for the missing manifest of a 1.3.x install: when there is none,
 * the fixed list of the 18 old bundle names counts as managed. Each of those
 * folders that exists in `.claude/skills` (the only place 1.3.x wrote to)
 * becomes an entry with a checksum no folder can have, so the later steps
 * treat it as "changed" and save it to the backup root before replacing or
 * removing it. Such an entry is never written to disk.
 *
 * Folders of other names (`git-foo`, `design-mine`, ...) are never listed.
 */
final class BuildLegacyManifest
{
    /** Matches no real checksum: "there is no proof this folder is unchanged". */
    public const UNKNOWN_CHECKSUM = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param Closure(string, string): ?string $resolveManagedFolder
     */
    public function __construct(
        private readonly Closure $resolveManagedFolder,
    ) {
    }

    /**
     * @return ?Manifest null when the manifest is not simply missing or no old folder exists
     */
    public function __invoke(ManifestReadResult $read, string $projectRoot): ?Manifest
    {
        $realRoot = realpath($projectRoot);
        if ($read->state !== ManifestState::Missing || $realRoot === false) {
            return null;
        }

        $entries = [];
        foreach (array_keys(RenamedSkills::MAPPING) as $oldName) {
            $key = ResolveTargets::CLAUDE_SKILLS_DIR . '/' . $oldName;
            $folder = ($this->resolveManagedFolder)($realRoot, $key);
            if ($folder !== null && $folder !== '') {
                $entries[$key] = ['source' => ScanPluginSkills::SOURCE_PACKAGE, 'sha256' => self::UNKNOWN_CHECKSUM];
            }
        }

        return $entries === [] ? null : new Manifest(Manifest::SCHEMA_VERSION, '0.0.0', $entries);
    }
}
