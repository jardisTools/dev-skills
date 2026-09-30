<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;

/**
 * Gate for "no downgrade" (R10b): a manifest written by a newer schema or
 * plugin version means this plugin must not touch anything. The gate only runs
 * the given work when the manifest is not too new and otherwise reports why
 * it did not.
 */
final class GuardManifestVersion
{
    /**
     * @param Closure(string, string): ManifestReadResult $readManifest
     */
    public function __construct(
        private readonly Closure $readManifest,
    ) {
    }

    /**
     * @param Closure(): void $proceed the work that changes the project
     * @return ?string warning text when the work was blocked, null when it ran
     */
    public function __invoke(string $projectRoot, string $pluginVersion, Closure $proceed): ?string
    {
        $read = ($this->readManifest)($projectRoot . '/' . Manifest::FILE, $pluginVersion);
        if ($read->state === ManifestState::TooNew) {
            return 'nothing was changed: ' . $read->warning;
        }

        $proceed();

        return null;
    }
}
