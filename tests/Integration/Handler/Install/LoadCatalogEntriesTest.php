<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Handler\Install\LoadCatalogEntries;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class LoadCatalogEntriesTest extends TestCase
{
    public function testMissingManifestYieldsNoEntries(): void
    {
        $project = new TempProject();
        try {
            self::assertSame([], (new LoadCatalogEntries())($project->root));
        } finally {
            $project->cleanup();
        }
    }

    public function testBrokenManifestYieldsNoEntries(): void
    {
        $project = new TempProject();
        try {
            $project->writeFile('catalog/manifest.json', '{not json');
            self::assertSame([], (new LoadCatalogEntries())($project->root));
        } finally {
            $project->cleanup();
        }
    }

    public function testReadsTheRealManifest(): void
    {
        self::assertNotSame([], (new LoadCatalogEntries())(dirname(__DIR__, 4)));
    }
}
