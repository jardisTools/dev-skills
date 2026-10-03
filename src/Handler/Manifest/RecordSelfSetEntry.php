<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\SelfSetEntry;

/**
 * Notes in the manifest what the plugin set in a foreign file. The manifest is the one the
 * skills part of the run has just written; a missing one is started empty. An unhealthy
 * manifest is not overwritten: the caller gets an exception and reports it.
 */
final class RecordSelfSetEntry
{
    /**
     * @param Closure(string, string): ManifestReadResult $readManifest
     * @param Closure(string, Manifest): void             $writeManifest
     */
    public function __construct(
        private readonly Closure $readManifest,
        private readonly Closure $writeManifest,
    ) {
    }

    /**
     * @param string $key project-relative path of the foreign file
     */
    public function __invoke(string $projectRoot, string $key, SelfSetEntry $entry): void
    {
        $path = $projectRoot . '/' . Manifest::FILE;
        $read = ($this->readManifest)($path, ResolvePluginVersion::DEV_VERSION);

        $current = match ($read->state) {
            ManifestState::Healthy => $read->manifest,
            ManifestState::Missing => new Manifest(Manifest::SCHEMA_VERSION, '0.0.0'),
            ManifestState::Defective, ManifestState::TooNew => null,
        };
        if ($current === null) {
            throw new \RuntimeException('the manifest is not healthy, so "' . $key . '" cannot be recorded in it');
        }

        ($this->writeManifest)($path, new Manifest(
            $current->schemaVersion,
            $current->pluginVersion,
            $current->entries,
            [...$current->selfSet, $key => $entry],
            $current->profile,
        ));
    }
}
