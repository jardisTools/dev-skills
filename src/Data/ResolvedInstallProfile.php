<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * The outcome of the profile resolution: the profile of this run and the profile the manifest records.
 * A profile that only keeps an installation from before the profile existed is not recorded
 * (`manifestProfile` is `null`), so the manifest stays "from before" and the installation keeps its
 * skills in every later run.
 */
final readonly class ResolvedInstallProfile
{
    public function __construct(
        public InstallProfile $profile,
        public ?InstallProfile $manifestProfile,
    ) {
    }
}
