<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use Composer\Composer;

/**
 * Resolves the plugin's own release version (`major.minor.patch`) from the
 * local repository of the running Composer. Dev versions without a release
 * number resolve to `0.0.0`.
 */
final class ResolvePluginVersion
{
    private const PACKAGE = 'jardis/dev-skills';

    public function __invoke(Composer $composer): string
    {
        $package = $composer->getRepositoryManager()->getLocalRepository()->findPackage(self::PACKAGE, '*');

        return preg_match('/^v?(\d+\.\d+\.\d+)/', (string) $package?->getPrettyVersion(), $match) === 1
            ? $match[1]
            : '0.0.0';
    }
}
