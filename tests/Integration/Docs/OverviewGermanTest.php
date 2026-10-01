<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The German overview page `docs/overview.de.html` against the repository: the
 * skill names on the skill map are exactly the folders of `skills/`, the page has
 * its five areas, the tool coverage matrix names the five tools and carries the
 * legend with the retrieval date, and the page states facts only.
 */
final class OverviewGermanTest extends TestCase
{
    private const BUNDLED_SKILLS = 33;
    private const AREAS = 5;
    private const BUILDING_BLOCKS = 10;

    private string $root;
    private OverviewPage $page;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $this->page = new OverviewPage($this->root . '/docs/overview.de.html');
    }

    public function testPageDeclaresGermanAsLanguage(): void
    {
        $html = $this->page->xpath->query('/html')?->item(0);

        self::assertNotNull($html);
        self::assertSame('de', $html->attributes?->getNamedItem('lang')?->nodeValue);
    }

    public function testSkillMapNamesAreExactlyTheSkillFolders(): void
    {
        $folders = $this->skillFolders();
        $names   = $this->page->skillMapNames();

        self::assertCount(self::BUNDLED_SKILLS, $folders);
        self::assertSame([], array_values(array_diff($folders, $names)), 'skill folder missing on the page');
        self::assertSame([], array_values(array_diff($names, $folders)), 'page names a skill without a folder');
        self::assertSame(count($names), count(array_unique($names)), 'a skill name appears twice');
    }

    public function testPageHasFiveAreas(): void
    {
        self::assertSame(self::AREAS, $this->page->xpath->query('//h2')?->length);
    }

    public function testToolCoverageMatrixHasFiveToolColumnsAndLegend(): void
    {
        $headers = $this->page->matrixHeaders();
        self::assertCount(7, $headers, 'building block, five tools, fallback');
        self::assertSame(
            ['Claude Code', 'Codex CLI/IDE', 'Cursor', 'GitHub Copilot', 'Gemini CLI'],
            array_slice($headers, 1, 5)
        );

        $rows = $this->page->matrixSymbolRows();
        self::assertGreaterThanOrEqual(self::BUILDING_BLOCKS, count($rows), 'nine building blocks plus the commit-msg hook');
        foreach ($rows as $symbols) {
            self::assertCount(5, $symbols);
            self::assertSame([], array_values(array_diff($symbols, ['✔', '◐', '✘', '?'])));
        }

        $legend = $this->page->matrixLegend();
        foreach (['✔', '◐', '✘', '?', 'abgerufen am 2026-10-01'] as $part) {
            self::assertStringContainsString($part, $legend);
        }
    }

    public function testGuaranteeRowUsesTheGermanLevels(): void
    {
        $row = $this->page->guaranteeRow();

        self::assertStringContainsString('getestet', $row);
        self::assertStringContainsString('aus Doku belegt, nicht getestet', $row);
    }

    public function testPageStatesFactsAndLeavesNothingForDecision(): void
    {
        $text = mb_strtolower($this->page->html);

        foreach (['vorschlag', 'zur abnahme', 'zu bestätigen'] as $word) {
            self::assertStringNotContainsString($word, $text);
        }
    }

    /**
     * @return list<string>
     */
    private function skillFolders(): array
    {
        $folders = [];
        foreach (glob($this->root . '/skills/*', GLOB_ONLYDIR) ?: [] as $path) {
            $folders[] = basename($path);
        }
        sort($folders);

        return $folders;
    }
}
