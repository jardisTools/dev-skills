<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Handler\Manifest\GuardManifestVersion;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class GuardManifestVersionTest extends TestCase
{
    private TempProject $project;
    private int $runs = 0;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testTooNewSchemaBlocksTheWorkAndNamesBothVersions(): void
    {
        $this->project->writeFile(Manifest::FILE, '{"schemaVersion":2,"pluginVersion":"1.4.0","paths":{}}');

        $warning = $this->guard('1.4.0');

        self::assertSame(0, $this->runs);
        self::assertNotNull($warning);
        self::assertStringContainsString('schema 2', $warning);
        self::assertStringContainsString('schema 1', $warning);
        self::assertStringContainsString('plugin 1.4.0', $warning);
    }

    public function testTooNewPluginVersionBlocksTheWork(): void
    {
        $this->project->writeFile(Manifest::FILE, '{"schemaVersion":1,"pluginVersion":"2.0.0","paths":{}}');

        self::assertNotNull($this->guard('1.4.0'));
        self::assertSame(0, $this->runs);
    }

    public function testDevCheckoutDoesNotBlockAManifestWrittenByARelease(): void
    {
        // A dev checkout resolves to 0.0.0: only the schema version may decide there.
        $this->project->writeFile(Manifest::FILE, '{"schemaVersion":1,"pluginVersion":"1.4.0","paths":{}}');

        self::assertNull($this->guard('0.0.0'));
        self::assertSame(1, $this->runs);
    }

    public function testDevCheckoutStillBlocksANewerSchema(): void
    {
        $this->project->writeFile(Manifest::FILE, '{"schemaVersion":2,"pluginVersion":"1.4.0","paths":{}}');

        self::assertNotNull($this->guard('0.0.0'));
        self::assertSame(0, $this->runs);
    }

    public function testMissingHealthyAndDefectiveManifestsLetTheWorkRun(): void
    {
        self::assertNull($this->guard('1.4.0'));

        $this->project->writeFile(Manifest::FILE, '{"schemaVersion":1,"pluginVersion":"1.0.0","paths":{}}');
        self::assertNull($this->guard('1.4.0'));

        $this->project->writeFile(Manifest::FILE, 'broken');
        self::assertNull($this->guard('1.4.0'));

        self::assertSame(3, $this->runs);
    }

    private function guard(string $pluginVersion): ?string
    {
        $guard = new GuardManifestVersion((new ReadManifest())->__invoke(...));

        return $guard($this->project->root, $pluginVersion, function (): void {
            ++$this->runs;
        });
    }
}
