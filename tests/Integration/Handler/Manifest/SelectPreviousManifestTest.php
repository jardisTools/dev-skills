<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Handler\Manifest\SelectPreviousManifest;
use PHPUnit\Framework\TestCase;

final class SelectPreviousManifestTest extends TestCase
{
    public function testHealthyManifestIsThePreviousState(): void
    {
        $manifest = new Manifest(Manifest::SCHEMA_VERSION, '1.0.0');

        self::assertSame($manifest, (new SelectPreviousManifest())(
            new ManifestReadResult(ManifestState::Healthy, $manifest),
        ));
    }

    public function testMissingDefectiveAndTooNewManifestsYieldNoPreviousState(): void
    {
        $select = new SelectPreviousManifest();

        self::assertNull($select(new ManifestReadResult(ManifestState::Missing)));
        self::assertNull($select(new ManifestReadResult(ManifestState::Defective, null, 'broken')));
        self::assertNull($select(new ManifestReadResult(
            ManifestState::TooNew,
            new Manifest(Manifest::SCHEMA_VERSION + 1, '9.9.9'),
            'newer',
        )));
    }
}
