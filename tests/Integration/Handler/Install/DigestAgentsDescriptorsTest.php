<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\BuildManagedBlock;
use JardisTools\DevSkills\Handler\Install\DigestAgentsDescriptors;
use JardisTools\DevSkills\Handler\Install\LoadCatalogEntries;
use PHPUnit\Framework\TestCase;

final class DigestAgentsDescriptorsTest extends TestCase
{
    public function testMatchesCatalogEntryAndSkillsByPackage(): void
    {
        $catalog = (new LoadCatalogEntries())(dirname(__DIR__, 4));

        $digests = (new DigestAgentsDescriptors())(
            [
                new AgentsDescriptor('jardisadapter/cache', "# jardisadapter/cache\n\nIntro.\n\n## Usage essentials\n\n- x\n"),
                new AgentsDescriptor('jardis/unknown', "# jardis/unknown\n\nUnknown intro.\n\n## Usage essentials\n\n- y\n"),
            ],
            $catalog,
            [
                new SkillDescriptor('adapter-cache', '/x', 'jardisadapter/cache'),
                new SkillDescriptor('other', '/y', 'jardis/else'),
            ],
        );

        self::assertStringContainsString('- **Use when:**', $digests[0]->content);
        self::assertStringContainsString('load skill `adapter-cache`', $digests[0]->content);
        self::assertStringNotContainsString('Use when', $digests[1]->content);
        self::assertStringContainsString('Unknown intro.', $digests[1]->content);
        self::assertStringNotContainsString('load skill', $digests[1]->content);
    }

    public function testBlockOfThirteenPackagesStaysBelow16KiB(): void
    {
        $catalog = (new LoadCatalogEntries())(dirname(__DIR__, 4));
        $descriptors = [];
        $skills = [];
        for ($i = 1; $i <= 13; $i++) {
            $package = 'jardissupport/pkg' . $i;
            $descriptors[] = new AgentsDescriptor(
                $package,
                "# {$package}\n\nIntro of package {$i}.\n\n## Usage essentials\n\n" . str_repeat("- rule line\n", 250)
                . "\n## Full reference\n\nhttps://docs.jardis.io/en/support/pkg{$i}\n",
            );
            $skills[] = new SkillDescriptor('support-pkg' . $i, '/x', $package);
        }

        $full = (new BuildManagedBlock())($descriptors);
        $short = (new BuildManagedBlock())((new DigestAgentsDescriptors())($descriptors, $catalog, $skills));

        self::assertGreaterThan(32768, strlen($full));
        self::assertLessThan(16384, strlen($short));
    }
}
