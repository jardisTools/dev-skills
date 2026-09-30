<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\SelfSetEntry;
use JardisTools\DevSkills\Handler\Manifest\ReadManifest;
use JardisTools\DevSkills\InstallAddons;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnsureGeminiContextTest extends TestCase
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

    public function testCreatesSettingsWhenMissing(): void
    {
        $report = $this->ensure();

        self::assertSame(
            "{\n  \"context\": {\"fileName\": [\"AGENTS.md\", \"GEMINI.md\"]}\n}\n",
            file_get_contents($this->project->path(self::SETTINGS)),
        );
        self::assertSame([], $report->warnings());
        $noted = $this->noted();
        self::assertNotNull($noted);
        self::assertTrue($noted->fileCreated);
    }

    public function testInsertsContextKeyTextuallyKeepingOtherBytesIncludingCrlf(): void
    {
        $original = "{\r\n  \"theme\": \"dark\",\r\n  \"mcpServers\": {\"a\": {\"command\": \"x\"}}\r\n}\r\n";
        $this->project->writeFile(self::SETTINGS, $original);

        $this->ensure();

        $inserted = "\r\n  \"context\": {\"fileName\": [\"AGENTS.md\", \"GEMINI.md\"]},";
        $after = (string) file_get_contents($this->project->path(self::SETTINGS));
        self::assertSame(substr_replace($original, $inserted, 1, 0), $after);
        self::assertSame(0, substr_count(str_replace("\r\n", '', $after), "\n"), 'no bare LF in a CRLF file');
        $noted = $this->noted();
        self::assertNotNull($noted);
        self::assertFalse($noted->fileCreated);
        self::assertSame(['AGENTS.md', 'GEMINI.md'], $this->fileNames($after));
    }

    /**
     * @return array<string, array{string, string}> original => expected
     */
    public static function layouts(): array
    {
        $entry = '"context": {"fileName": ["AGENTS.md", "GEMINI.md"]}';

        return [
            'inline object' => ['{"theme":"dark"}', '{' . $entry . ',"theme":"dark"}'],
            'empty object' => ['{}', '{' . $entry . '}'],
            'tab indent' => ["{\n\t\"a\": 1\n}\n", "{\n\t" . $entry . ",\n\t\"a\": 1\n}\n"],
            'brace inside a string' => [
                "{\n  \"note\": \"{ \\\" ] }\"\n}",
                "{\n  " . $entry . ",\n  \"note\": \"{ \\\" ] }\"\n}",
            ],
        ];
    }

    #[DataProvider('layouts')]
    public function testInsertionFollowsTheLayoutOfTheObject(string $original, string $expected): void
    {
        $this->project->writeFile(self::SETTINGS, $original);

        $this->ensure();

        self::assertSame($expected, file_get_contents($this->project->path(self::SETTINGS)));
    }

    public function testContextWithoutFileNameGetsTheMemberInsideIt(): void
    {
        $this->project->writeFile(self::SETTINGS, "{\n  \"context\": {\n    \"discoveryMaxDirs\": 5\n  }\n}\n");

        $this->ensure();

        self::assertSame(
            "{\n  \"context\": {\n    \"fileName\": [\"AGENTS.md\", \"GEMINI.md\"],\n    \"discoveryMaxDirs\": 5\n  }\n}\n",
            file_get_contents($this->project->path(self::SETTINGS)),
        );
    }

    public function testStringFileNameBecomesListWithAgentsMdOnce(): void
    {
        $this->project->writeFile(self::SETTINGS, '{"context": {"fileName": "GEMINI.md", "x": 1}}');

        $this->ensure();
        $first = (string) file_get_contents($this->project->path(self::SETTINGS));
        $this->ensure();

        self::assertSame('{"context": {"fileName": ["GEMINI.md", "AGENTS.md"], "x": 1}}', $first);
        self::assertSame($first, file_get_contents($this->project->path(self::SETTINGS)), 'second run changes nothing');
        self::assertSame(['GEMINI.md', 'AGENTS.md'], $this->fileNames($first));
    }

    public function testListFileNameGetsAgentsMdAppendedAndKeepsItsEntries(): void
    {
        $this->project->writeFile(self::SETTINGS, "{\"context\": {\"fileName\": [ \"GEMINI.md\",\n \"TEAM.md\" ]}}");

        $this->ensure();

        self::assertSame(
            "{\"context\": {\"fileName\": [ \"GEMINI.md\",\n \"TEAM.md\", \"AGENTS.md\" ]}}",
            file_get_contents($this->project->path(self::SETTINGS)),
        );
    }

    public function testAgentsMdAlreadyListedLeavesFileAndManifestAlone(): void
    {
        $original = "{\n  \"context\": {\"fileName\": [\"AGENTS.md\"]}\n}\n";
        $this->project->writeFile(self::SETTINGS, $original);

        $this->ensure();

        self::assertSame($original, file_get_contents($this->project->path(self::SETTINGS)));
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unextendable(): array
    {
        return [
            'context is a string' => ['{"context": "x"}'],
            'fileName is a number' => ['{"context": {"fileName": 3}}'],
            'fileName list holds a number' => ['{"context": {"fileName": ["GEMINI.md", 3]}}'],
            'top level is a list' => ['["a"]'],
        ];
    }

    #[DataProvider('unextendable')]
    public function testNotCharacterTruePossibleWarnsAndLeavesFileUntouched(string $original): void
    {
        $this->project->writeFile(self::SETTINGS, $original);

        $report = $this->ensureGuarded();

        self::assertSame($original, file_get_contents($this->project->path(self::SETTINGS)));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('add-on "gemini-context" failed and was skipped', $report->warnings()[0]);
        self::assertFileDoesNotExist($this->project->path(Manifest::FILE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidJson(): array
    {
        return [
            'broken' => ['{"context": '],
            'comment' => ["{\n  // my settings\n  \"a\": 1\n}\n"],
            'trailing comma' => ['{"a": 1,}'],
            'empty file' => [''],
        ];
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonWarnsAndLeavesFileUntouched(string $original): void
    {
        $this->project->writeFile(self::SETTINGS, $original);

        $report = $this->ensureGuarded();

        self::assertSame($original, file_get_contents($this->project->path(self::SETTINGS)));
        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('not valid JSON', $report->warnings()[0]);
    }

    public function testLinkLeadingOutOfTheProjectIsNotWrittenThrough(): void
    {
        $outside = new TempProject('dev-skills-outside-');
        try {
            $outside->writeFile('settings.json', '{"a": 1}');
            $this->project->mkdir('.gemini');
            self::assertTrue(symlink($outside->path('settings.json'), $this->project->path(self::SETTINGS)));

            $report = $this->ensureGuarded();

            self::assertSame('{"a": 1}', file_get_contents($outside->path('settings.json')));
            self::assertCount(1, $report->warnings());
            self::assertStringContainsString('leads out of the project', $report->warnings()[0]);
        } finally {
            $outside->cleanup();
        }
    }

    private function ensure(): InstallReport
    {
        $report = new InstallReport();
        AddonFactory::ensureGemini()($this->project->root, $this->project->path('vendor'), $report);

        return $report;
    }

    private function ensureGuarded(): InstallReport
    {
        $report = new InstallReport();
        (new InstallAddons(['gemini-context' => AddonFactory::ensureGemini()->__invoke(...)]))(
            $this->project->root,
            $this->project->path('vendor'),
            $report,
        );

        return $report;
    }

    private function noted(): ?SelfSetEntry
    {
        return (new ReadManifest())($this->project->path(Manifest::FILE), '0.0.0')
            ->manifest?->selfSet[self::SETTINGS] ?? null;
    }

    /**
     * @return list<string>
     */
    private function fileNames(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['context']['fileName'];
    }
}
