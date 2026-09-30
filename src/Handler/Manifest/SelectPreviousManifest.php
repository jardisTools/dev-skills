<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;

/**
 * Decides which manifest counts as "the previous install state": only a healthy
 * one. Missing, defective and too-new manifests yield null, so nothing is
 * assumed about what an earlier run installed.
 */
final class SelectPreviousManifest
{
    public function __invoke(ManifestReadResult $read): ?Manifest
    {
        return match ($read->state) {
            ManifestState::Healthy => $read->manifest,
            ManifestState::Missing, ManifestState::Defective, ManifestState::TooNew => null,
        };
    }
}
