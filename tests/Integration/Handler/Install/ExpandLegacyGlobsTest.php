<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Handler\Install\ExpandLegacyGlobs;
use PHPUnit\Framework\TestCase;

final class ExpandLegacyGlobsTest extends TestCase
{
    public function testRulesGlobReachesTheFourFoundationSkills(): void
    {
        $expanded = (new ExpandLegacyGlobs())(['rules-*']);

        self::assertSame(
            [
                'rules-*',
                'foundation-architecture',
                'foundation-patterns',
                'foundation-testing',
                'foundation-frontend-review',
            ],
            $expanded,
        );
    }

    public function testDoGitGlobReachesTheGitSkillsOfTheOldNamesItMatches(): void
    {
        $expanded = (new ExpandLegacyGlobs())(['do-git-*']);

        self::assertSame(
            ['do-git-*', 'git-start-branch', 'git-commit-change', 'git-push-and-open-pr', 'git-check-compliance'],
            $expanded,
        );
    }

    public function testEverySingleOldNameReachesItsNewName(): void
    {
        foreach (RenamedSkills::MAPPING as $old => $new) {
            self::assertSame([$old, $new], (new ExpandLegacyGlobs())([$old]), $old);
        }
    }

    public function testGlobsWithoutAnOldNameStayAsTheyAre(): void
    {
        self::assertSame(['adapter-*', 'git-foo'], (new ExpandLegacyGlobs())(['adapter-*', 'git-foo']));
        self::assertSame([], (new ExpandLegacyGlobs())([]));
    }

    public function testNewNamesAppearOnlyOnce(): void
    {
        $expanded = (new ExpandLegacyGlobs())(['rules-*', 'rules-testing', 'foundation-testing']);

        self::assertSame(
            ['rules-*', 'rules-testing', 'foundation-testing', 'foundation-architecture', 'foundation-patterns', 'foundation-frontend-review'],
            $expanded,
        );
    }
}
