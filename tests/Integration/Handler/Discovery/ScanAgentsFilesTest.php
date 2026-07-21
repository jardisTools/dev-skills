<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Discovery;

use JardisTools\DevSkills\Handler\Discovery\ScanAgentsFiles;
use JardisTools\DevSkills\Handler\Install\AnalyzeAgentsMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ScanAgentsFilesTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testCollectsAgentsMdContent(): void
    {
        $this->project->writeFile(
            'vendor/jardisadapter/cache/AGENTS.md',
            "# jardisadapter/cache\n\nCache rules.\n",
        );

        $descriptors = (new ScanAgentsFiles())($this->project->path('vendor'));

        self::assertCount(1, $descriptors);
        self::assertSame('jardisadapter/cache', $descriptors[0]->sourcePackage);
        self::assertStringContainsString('Cache rules.', $descriptors[0]->content);
    }

    public function testIgnoresNonJardisVendors(): void
    {
        $this->project->writeFile('vendor/acme/foo/AGENTS.md', 'nope');
        $this->project->writeFile('vendor/jardissupport/data/AGENTS.md', 'yes');

        $descriptors = (new ScanAgentsFiles())($this->project->path('vendor'));

        self::assertCount(1, $descriptors);
        self::assertSame('jardissupport/data', $descriptors[0]->sourcePackage);
    }

    public function testStripsManagedBlockFromSourceContent(): void
    {
        $this->project->writeFile(
            'vendor/jardiscore/kernel/AGENTS.md',
            "# kernel\n\nKernel rules.\n\n"
            . AnalyzeAgentsMd::HEADER . "\n"
            . "aggregated junk\n"
            . AnalyzeAgentsMd::FOOTER . "\n",
        );

        $descriptors = (new ScanAgentsFiles())($this->project->path('vendor'));

        self::assertCount(1, $descriptors);
        self::assertStringContainsString('Kernel rules.', $descriptors[0]->content);
        self::assertStringNotContainsString(AnalyzeAgentsMd::HEADER, $descriptors[0]->content);
        self::assertStringNotContainsString(AnalyzeAgentsMd::FOOTER, $descriptors[0]->content);
    }

    public function testReturnsEmptyForMissingVendor(): void
    {
        $descriptors = (new ScanAgentsFiles())($this->project->path('none'));

        self::assertSame([], $descriptors);
    }
}
