<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadManifestTest extends TestCase
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

    public function testAbsentFileIsMissing(): void
    {
        $result = (new ReadManifest())($this->project->path(Manifest::FILE), '1.0.0');

        self::assertSame(ManifestState::Missing, $result->state);
        self::assertNull($result->manifest);
    }

    public function testValidManifestIsHealthy(): void
    {
        $path = $this->project->writeFile(Manifest::FILE, $this->json(1, '1.0.0', [
            '.claude/skills/demo' => ['source' => 'bundle', 'sha256' => str_repeat('e', 64)],
        ]));

        $result = (new ReadManifest())($path, '1.0.0');

        self::assertSame(ManifestState::Healthy, $result->state);
        self::assertSame('1.0.0', $result->manifest?->pluginVersion);
        self::assertSame('bundle', $result->manifest->entries['.claude/skills/demo']['source']);
        self::assertSame('', $result->warning);
    }

    #[DataProvider('defectiveProvider')]
    public function testBrokenContentIsDefectiveWithWarningAndNoThrow(string $content): void
    {
        $path = $this->project->writeFile(Manifest::FILE, $content);

        $result = (new ReadManifest())($path, '1.0.0');

        self::assertSame(ManifestState::Defective, $result->state);
        self::assertNull($result->manifest);
        self::assertStringContainsString('defective manifest', $result->warning);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function defectiveProvider(): array
    {
        return [
            'not json' => ['{ nope'],
            'json scalar' => ['42'],
            'no schema version' => ['{"pluginVersion":"1.0.0","paths":{}}'],
            'schema version as string' => ['{"schemaVersion":"1","pluginVersion":"1.0.0","paths":{}}'],
            'paths not an object' => ['{"schemaVersion":1,"pluginVersion":"1.0.0","paths":"x"}'],
            'entry without checksum' => ['{"schemaVersion":1,"pluginVersion":"1.0.0","paths":{"p":{"source":"bundle"}}}'],
            'entry with short checksum' => [
                '{"schemaVersion":1,"pluginVersion":"1.0.0","paths":{"p":{"source":"bundle","sha256":"abc"}}}',
            ],
        ];
    }

    public function testNewerSchemaVersionIsTooNewWithBothVersions(): void
    {
        $path = $this->project->writeFile(Manifest::FILE, $this->json(7, '1.0.0', []));

        $result = (new ReadManifest())($path, '1.0.0');

        self::assertSame(ManifestState::TooNew, $result->state);
        self::assertSame(7, $result->manifest?->schemaVersion);
        self::assertStringContainsString('schema 7', $result->warning);
        self::assertStringContainsString('plugin 1.0.0', $result->warning);
    }

    public function testNewerPluginVersionIsTooNewWithBothVersions(): void
    {
        $path = $this->project->writeFile(Manifest::FILE, $this->json(1, '3.1.0', []));

        $result = (new ReadManifest())($path, '2.0.0');

        self::assertSame(ManifestState::TooNew, $result->state);
        self::assertSame('3.1.0', $result->manifest?->pluginVersion);
        self::assertStringContainsString('plugin 3.1.0', $result->warning);
        self::assertStringContainsString('plugin 2.0.0', $result->warning);
    }

    public function testOlderPluginVersionIsStillHealthy(): void
    {
        $path = $this->project->writeFile(Manifest::FILE, $this->json(1, '1.0.0', []));

        self::assertSame(ManifestState::Healthy, (new ReadManifest())($path, '2.0.0')->state);
    }

    /**
     * @param array<string, array<string, string>> $paths
     */
    private function json(int $schema, string $plugin, array $paths): string
    {
        return json_encode(['schemaVersion' => $schema, 'pluginVersion' => $plugin, 'paths' => (object) $paths], JSON_THROW_ON_ERROR);
    }
}
