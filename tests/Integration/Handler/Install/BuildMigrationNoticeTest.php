<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\BuildMigrationNotice;
use PHPUnit\Framework\TestCase;

final class BuildMigrationNoticeTest extends TestCase
{
    public function testNoRedirectMeansNoNotice(): void
    {
        self::assertNull((new BuildMigrationNotice())([]));
    }

    public function testNoticeNamesEveryOldNameWithItsNewName(): void
    {
        $notice = (new BuildMigrationNotice())([
            new SkillDescriptor('rules-architecture', '', 'jardis/dev-skills'),
            new SkillDescriptor('do-git-branch', '', 'jardis/dev-skills'),
        ]);

        self::assertNotNull($notice);
        self::assertStringContainsString('2 bundle skills were renamed', $notice);
        self::assertStringContainsString('rules-architecture -> foundation-architecture', $notice);
        self::assertStringContainsString('do-git-branch -> git-start-branch', $notice);
    }
}
