<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Uninstall;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class RemoveGeminiContextTest extends TestCase
{
    private const SETTINGS = '.gemini/settings.json';

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testDeletesPluginCreatedFileWhenOnlyEmptyScaffoldRemains(): void
    {
        $this->install();
        self::assertFileExists($this->project->path(self::SETTINGS));

        $report = $this->remove();

        self::assertFileDoesNotExist($this->project->path(self::SETTINGS));
        self::assertDirectoryDoesNotExist($this->project->path('.gemini'), 'the emptied folder goes with it');
        self::assertSame([], $report->warnings());
    }

    public function testKeepsFileAndForeignKeysWhenOnlyOwnEntryIsRemoved(): void
    {
        $original = "{\r\n  \"theme\": \"dark\",\r\n  \"mcpServers\": {\"a\": {\"command\": \"x\"}}\r\n}\r\n";
        $this->project->writeFile(self::SETTINGS, $original);
        $this->install();
        self::assertNotSame($original, file_get_contents($this->project->path(self::SETTINGS)));

        $this->remove();

        self::assertSame($original, file_get_contents($this->project->path(self::SETTINGS)));
    }

    public function testStringFileNameGoesBackToTheOriginalString(): void
    {
        $original = '{"context": {"fileName": "GEMINI.md", "x": 1}}';
        $this->project->writeFile(self::SETTINGS, $original);
        $this->install();

        $this->remove();

        self::assertSame($original, file_get_contents($this->project->path(self::SETTINGS)));
    }

    public function testKeepsPluginCreatedFileThatGotForeignContentAfterwards(): void
    {
        $this->install();
        $path = $this->project->path(self::SETTINGS);
        $edited = str_replace("\"GEMINI.md\"]}\n}", "\"GEMINI.md\"]},\n  \"theme\": \"dark\"\n}", (string) file_get_contents($path));
        self::assertNotSame($edited, file_get_contents($path));
        file_put_contents($path, $edited);

        $this->remove();

        self::assertSame("{\n  \"theme\": \"dark\"\n}\n", file_get_contents($path));
    }

    public function testWarnsAndKeepsFileWhenTheEntryWasEditedByHand(): void
    {
        $this->install();
        $path = $this->project->path(self::SETTINGS);
        $edited = str_replace('"GEMINI.md"', '"GEMINI.md", "TEAM.md"', (string) file_get_contents($path));
        file_put_contents($path, $edited);

        $report = $this->remove();

        self::assertSame($edited, file_get_contents($path));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('left untouched', $report->warnings()[0]);
    }

    public function testWithoutManifestNoteNothingHappens(): void
    {
        $original = "{\"context\": {\"fileName\": [\"AGENTS.md\"]}}\n";
        $this->project->writeFile(self::SETTINGS, $original);

        $report = new UninstallReport();
        AddonFactory::removeGemini()($this->project->root, null, $report);

        self::assertSame($original, file_get_contents($this->project->path(self::SETTINGS)));
        self::assertSame([], $report->warnings());
    }

    private function install(): void
    {
        AddonFactory::ensureGemini()($this->project->root, $this->project->path('vendor'), new InstallReport());
    }

    private function remove(): UninstallReport
    {
        $manifest = (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')->manifest;
        $report = new UninstallReport();
        AddonFactory::removeGemini()($this->project->root, $manifest, $report);

        return $report;
    }
}
