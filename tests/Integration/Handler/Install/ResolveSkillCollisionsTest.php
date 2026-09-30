<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\ResolveSkillCollisions;
use PHPUnit\Framework\TestCase;

final class ResolveSkillCollisionsTest extends TestCase
{
    public function testWithoutCollisionKeepsAllAndWarnsNot(): void
    {
        $bundle = [$this->skill('alpha', '/b/alpha', 'jardis/dev-skills')];
        $vendor = [$this->skill('beta', '/v/beta', 'vendor-a/pkg')];

        $result = (new ResolveSkillCollisions())($bundle, $vendor);

        self::assertSame([...$bundle, ...$vendor], $result->skills);
        self::assertSame([], $result->warnings);
    }

    public function testBundleWinsOverVendorWithBothPathsInWarning(): void
    {
        $bundle = $this->skill('alpha', '/b/alpha', 'jardis/dev-skills');
        $vendor = $this->skill('alpha', '/v/alpha', 'vendor-a/pkg');

        $result = (new ResolveSkillCollisions())([$bundle], [$vendor]);

        self::assertSame([$bundle], $result->skills);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('/b/alpha', $result->warnings[0]);
        self::assertStringContainsString('/v/alpha', $result->warnings[0]);
    }

    public function testAlphabeticallyFirstVendorPackageWins(): void
    {
        $zeta = $this->skill('alpha', '/v/zeta/alpha', 'vendor-z/pkg');
        $abc = $this->skill('alpha', '/v/abc/alpha', 'vendor-a/pkg');

        $result = (new ResolveSkillCollisions())([], [$zeta, $abc]);

        self::assertSame([$abc], $result->skills);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('/v/zeta/alpha', $result->warnings[0]);
        self::assertStringContainsString('/v/abc/alpha', $result->warnings[0]);
    }

    private function skill(string $name, string $dir, string $package): SkillDescriptor
    {
        return new SkillDescriptor($name, $dir, $package);
    }
}
