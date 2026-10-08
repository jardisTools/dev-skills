<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\CatalogEntry;
use JardisTools\DevSkills\Handler\Install\BuildPackageDigest;
use PHPUnit\Framework\TestCase;

final class BuildPackageDigestTest extends TestCase
{
    private const CONTENT = "# jardiscore/kernel\n\nIntro paragraph of the package.\n\n## Usage essentials\n\n"
        . "- long rule one\n- long rule two\n\n## Full reference\n\nhttps://docs.jardis.io/en/core/kernel\n";

    private function entry(): CatalogEntry
    {
        return new CatalogEntry(
            'jardiscore/kernel',
            'immutable domain kernel',
            'you bootstrap a domain',
            'composer require jardiscore/kernel',
        );
    }

    public function testShortBlockWithCatalogEntrySkillAndDocsUrl(): void
    {
        $digest = (new BuildPackageDigest())(
            new AgentsDescriptor('jardiscore/kernel', self::CONTENT),
            $this->entry(),
            ['core-kernel'],
        );

        self::assertSame('jardiscore/kernel', $digest->sourcePackage);
        self::assertSame(
            "# jardiscore/kernel\n\nImmutable domain kernel.\n\n- **Use when:** you bootstrap a domain\n"
            . "- **How:** load skill `core-kernel` before touching the API — it carries the usage essentials"
            . " and pitfalls.\n  Full reference: https://docs.jardis.io/en/core/kernel",
            $digest->content,
        );
        self::assertStringNotContainsString('long rule one', $digest->content);
    }

    public function testFallbackWithoutCatalogEntryUsesIntroAndNoUseWhen(): void
    {
        $digest = (new BuildPackageDigest())(
            new AgentsDescriptor('jardiscore/kernel', self::CONTENT),
            null,
            ['core-kernel'],
        );

        self::assertStringContainsString("# jardiscore/kernel\n\nIntro paragraph of the package.\n", $digest->content);
        self::assertStringNotContainsString('Use when', $digest->content);
        self::assertStringContainsString('load skill `core-kernel`', $digest->content);
    }

    public function testSeveralSkillsAreCommaSeparated(): void
    {
        $digest = (new BuildPackageDigest())(
            new AgentsDescriptor('jardiscore/kernel', self::CONTENT),
            $this->entry(),
            ['core-kernel', 'core-app'],
        );

        self::assertStringContainsString('load skills `core-kernel`, `core-app` before touching the API', $digest->content);
        self::assertStringContainsString('they carry the usage essentials', $digest->content);
    }

    public function testNoSkillsLeavesOnlyTheFullReference(): void
    {
        $digest = (new BuildPackageDigest())(
            new AgentsDescriptor('jardiscore/kernel', self::CONTENT),
            $this->entry(),
            [],
        );

        self::assertStringContainsString("- **How:** Full reference: https://docs.jardis.io/en/core/kernel", $digest->content);
        self::assertStringNotContainsString('load skill', $digest->content);
    }

    public function testMissingDocsUrlOmitsTheFullReference(): void
    {
        $digest = (new BuildPackageDigest())(
            new AgentsDescriptor('jardiscore/kernel', "# jardiscore/kernel\n\nIntro.\n\n## Full reference\n\nsee README\n"),
            $this->entry(),
            ['core-kernel'],
        );

        self::assertStringNotContainsString('Full reference', $digest->content);
        self::assertStringContainsString('load skill `core-kernel`', $digest->content);
    }

    public function testFullReferenceHeadingIsCaseInsensitiveAndUrlOutsideTheSectionIsIgnored(): void
    {
        $content = "# p/x\n\nIntro https://example.com/elsewhere\n\n## Full Reference\n\n[docs](https://docs.jardis.io/en/x).\n";

        $digest = (new BuildPackageDigest())(new AgentsDescriptor('p/x', $content), null, []);

        self::assertStringContainsString('Full reference: https://docs.jardis.io/en/x', $digest->content);
        self::assertStringNotContainsString('example.com/elsewhere' . "\n- **How", $digest->content);
    }

    public function testNoSkillsNoUrlNoEntryLeavesHeadingAndIntro(): void
    {
        $digest = (new BuildPackageDigest())(new AgentsDescriptor('p/x', "# p/x\n\nJust an intro.\n"), null, []);

        self::assertSame("# p/x\n\nJust an intro.", $digest->content);
    }
}
