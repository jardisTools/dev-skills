<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Docs;

use DOMDocument;
use DOMXPath;
use JardisTools\DevSkills\Data\RenamedSkills;
use PHPUnit\Framework\TestCase;

/**
 * The overview page `docs/overview.html` against the repository: the skill
 * names on the skill map are exactly the folders of `skills/`, no retired name
 * appears, the page has its five areas, and it states facts only.
 */
final class OverviewTest extends TestCase
{
    private const BUNDLED_SKILLS = 33;
    private const AREAS = 5;

    private string $root;
    private string $html;
    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $this->html = (string) file_get_contents($this->root . '/docs/overview.html');

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($this->html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
    }

    public function testSkillMapNamesAreExactlyTheSkillFolders(): void
    {
        $folders = $this->skillFolders();
        $names   = $this->skillMapNames();

        self::assertCount(self::BUNDLED_SKILLS, $folders);
        self::assertSame([], array_values(array_diff($folders, $names)), 'skill folder missing on the page');
        self::assertSame([], array_values(array_diff($names, $folders)), 'page names a skill without a folder');
        self::assertSame(count($names), count(array_unique($names)), 'a skill name appears twice');
    }

    public function testNoRetiredSkillNameAppearsAsSkillName(): void
    {
        $names = $this->skillMapNames();

        foreach (array_keys(RenamedSkills::MAPPING) as $retired) {
            self::assertNotContains($retired, $names);
        }
    }

    public function testPageHasFiveAreas(): void
    {
        self::assertSame(self::AREAS, $this->xpath->query('//h2')->length);
    }

    public function testPageStatesFactsAndLeavesNothingForDecision(): void
    {
        $text = strtolower($this->html);

        foreach (['proposal', 'for approval', 'to be confirmed'] as $word) {
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

    /**
     * @return list<string>
     */
    private function skillMapNames(): array
    {
        $query = "//*[contains(concat(' ', normalize-space(@class), ' '), ' sk ')]"
            . "//*[contains(concat(' ', normalize-space(@class), ' '), ' n ')]";

        $names = [];
        foreach ($this->xpath->query($query) as $node) {
            $names[] = trim($node->textContent);
        }
        sort($names);

        return $names;
    }
}
