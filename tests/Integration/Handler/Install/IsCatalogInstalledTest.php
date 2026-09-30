<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\IsCatalogInstalled;
use PHPUnit\Framework\TestCase;

final class IsCatalogInstalledTest extends TestCase
{
    public function testTrueWhenCatalogSkillSelected(): void
    {
        $skills = [new SkillDescriptor('packages-find-existing', '/b/packages-find-existing', 'jardis/dev-skills')];

        self::assertTrue((new IsCatalogInstalled())($skills));
    }

    public function testFalseWhenCatalogSkillAbsent(): void
    {
        $skills = [new SkillDescriptor('other-skill', '/b/other-skill', 'jardis/dev-skills')];

        self::assertFalse((new IsCatalogInstalled())($skills));
        self::assertFalse((new IsCatalogInstalled())([]));
    }
}
