<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;

/**
 * Takes the notes about foreign files out of the manifest once the plugin has reversed its changes there,
 * so a later run or the uninstall does not try to reverse them a second time. A manifest that is not
 * healthy, or holds none of the keys, is not touched.
 */
final class ForgetSelfSetEntries
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
     * @param list<string> $keys project-relative paths of the foreign files
     */
    public function __invoke(string $projectRoot, array $keys): void
    {
        $path = $projectRoot . '/' . Manifest::FILE;
        $read = ($this->readManifest)($path, ResolvePluginVersion::DEV_VERSION);
        $current = $read->state === ManifestState::Healthy ? $read->manifest : null;
        $kept = array_diff_key($current->selfSet ?? [], array_flip($keys));
        if ($current === null || count($kept) === count($current->selfSet)) {
            return;
        }

        ($this->writeManifest)($path, new Manifest(
            $current->schemaVersion,
            $current->pluginVersion,
            $current->entries,
            $kept,
            $current->profile,
        ));
    }
}
