<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\ComputeStaleBundledSkills;
use PHPUnit\Framework\TestCase;

final class ComputeStaleBundledSkillsTest extends TestCase
{
    public function testReturnsNamesPresentInAllButNotInKept(): void
    {
        $all = [
            $this->skill('schema-authoring'),
            $this->skill('rules-architecture'),
            $this->skill('tools-definition'),
        ];
        $kept = [$this->skill('schema-authoring')];

        $stale = (new ComputeStaleBundledSkills())($all, $kept);

        self::assertSame(['rules-architecture', 'tools-definition'], $stale);
    }

    public function testReturnsEmptyListWhenAllAreKept(): void
    {
        $all = [$this->skill('plan-requirements')];
        $stale = (new ComputeStaleBundledSkills())($all, $all);

        self::assertSame([], $stale);
    }

    public function testReturnsAllNamesWhenKeptIsEmpty(): void
    {
        $all = [
            $this->skill('plan-requirements'),
            $this->skill('rules-architecture'),
        ];

        $stale = (new ComputeStaleBundledSkills())($all, []);

        self::assertSame(['plan-requirements', 'rules-architecture'], $stale);
    }

    public function testReturnsEmptyListWhenBothInputsAreEmpty(): void
    {
        $stale = (new ComputeStaleBundledSkills())([], []);

        self::assertSame([], $stale);
    }

    private function skill(string $name): SkillDescriptor
    {
        return new SkillDescriptor($name, '/irrelevant/' . $name, 'jardis/dev-skills');
    }
}
