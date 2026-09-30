<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Composer\Package\RootPackageInterface;

/**
 * `composer install --no-dev` uninstalls dev requirements and so fires the
 * uninstall event for this plugin as well, although the project still wants
 * it. As long as the root package requires (or dev-requires) the plugin, its
 * removal is not a real removal and nothing may be deleted.
 */
final class IsSelfStillRequired
{
    private const SELF_PACKAGE_NAME = 'jardis/dev-skills';

    public function __invoke(RootPackageInterface $rootPackage): bool
    {
        return array_key_exists(self::SELF_PACKAGE_NAME, $rootPackage->getRequires())
            || array_key_exists(self::SELF_PACKAGE_NAME, $rootPackage->getDevRequires());
    }
}
