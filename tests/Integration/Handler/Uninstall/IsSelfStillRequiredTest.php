<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use Composer\Package\Link;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\MatchAllConstraint;
use JardisTools\DevSkills\Handler\Uninstall\IsSelfStillRequired;
use PHPUnit\Framework\TestCase;

final class IsSelfStillRequiredTest extends TestCase
{
    public function testRequiredInRequireMeansStillRequired(): void
    {
        self::assertTrue($this->check(['jardis/dev-skills'], []));
    }

    public function testRequiredInRequireDevMeansStillRequired(): void
    {
        self::assertTrue($this->check([], ['jardis/dev-skills']));
    }

    public function testNotRequiredAnywhereMeansRealRemoval(): void
    {
        self::assertFalse($this->check(['vendor/other'], ['vendor/dev-tool']));
    }

    /**
     * @param list<string> $require
     * @param list<string> $requireDev
     */
    private function check(array $require, array $requireDev): bool
    {
        $root = new RootPackage('consumer/app', '1.0.0.0', '1.0.0');
        $root->setRequires($this->links($require));
        $root->setDevRequires($this->links($requireDev));

        return (new IsSelfStillRequired())($root);
    }

    /**
     * @param list<string> $names
     * @return array<string, Link>
     */
    private function links(array $names): array
    {
        $links = [];
        foreach ($names as $name) {
            $links[$name] = new Link('consumer/app', $name, new MatchAllConstraint());
        }

        return $links;
    }
}
