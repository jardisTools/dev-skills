<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Handler\Validate\CheckSkillLinks;
use JardisTools\DevSkills\Handler\Validate\ParseSkillFrontmatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the shipped foundation skills against the bundle: they exist, keep their
 * descriptions short, and link only to skills that ship in the same bundle.
 */
final class BundleTest extends TestCase
{
    private const FOUNDATION_SKILLS = ['foundation-php', 'foundation-working-principles'];
    private const KNOWLEDGE_SKILLS = ['knowledge-maintain-pool', 'knowledge-record-decision'];

    public function testFoundationSkillsAreBundled(): void
    {
        foreach (self::FOUNDATION_SKILLS as $name) {
            $file = $this->skillFile($name);

            self::assertFileExists($file, sprintf('Skill %s is not bundled.', $name));

            $document = (new ParseSkillFrontmatter())((string) file_get_contents($file));
            self::assertNotNull($document, sprintf('Skill %s has no frontmatter.', $name));
            self::assertSame($name, $document['fields']['name'] ?? null);
            self::assertSame('crosscut', $document['fields']['zone'] ?? null);
            self::assertSame('C', $document['fields']['persona'] ?? null);
        }
    }

    #[DataProvider('foundationSkills')]
    public function testFoundationSkillDescriptionsHaveAtMostFortyFiveWords(string $name): void
    {
        $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
        self::assertNotNull($document);

        $description = $document['fields']['description'] ?? '';
        self::assertIsString($description);

        $words = preg_split('/\s+/', trim($description), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($words);
        self::assertNotEmpty($words);
        self::assertLessThanOrEqual(45, count($words), sprintf('Description of %s has %d words.', $name, count($words)));
    }

    public function testFoundationPhpOmitsReleaseLifecycleAndNamespaceAssignment(): void
    {
        $content = (string) file_get_contents($this->skillFile('foundation-php'));

        foreach (['Release', 'Packagist', 'release lifecycle', 'JardisCore', 'Application/Delivery'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $content,
                sprintf('foundation-php must not contain "%s".', $forbidden),
            );
        }
    }

    #[DataProvider('foundationSkills')]
    public function testFoundationSkillLinksResolveToShippedSkills(string $name): void
    {
        $file = $this->skillFile($name);

        self::assertSame([], (new CheckSkillLinks())($file));

        $document = (new ParseSkillFrontmatter())((string) file_get_contents($file));
        self::assertNotNull($document);
        foreach (['prerequisites', 'next'] as $field) {
            $links = $document['fields'][$field] ?? [];
            self::assertIsArray($links);
            foreach ($links as $link) {
                self::assertIsString($link);
                self::assertStringStartsNotWith('knowledge-', $link);
                self::assertStringStartsNotWith('process-', $link);
                self::assertFileExists($this->skillFile($link));
            }
        }
    }

    public function testKnowledgeSkillsAreBundledWithTemplates(): void
    {
        foreach (self::KNOWLEDGE_SKILLS as $name) {
            $file = $this->skillFile($name);

            self::assertFileExists($file, sprintf('Skill %s is not bundled.', $name));

            $document = (new ParseSkillFrontmatter())((string) file_get_contents($file));
            self::assertNotNull($document, sprintf('Skill %s has no frontmatter.', $name));
            self::assertSame($name, $document['fields']['name'] ?? null);
            self::assertSame('process', $document['fields']['zone'] ?? null);
            self::assertSame('O', $document['fields']['persona'] ?? null);
            self::assertSame([], (new CheckSkillLinks())($file));
        }

        $templates = dirname($this->skillFile('knowledge-maintain-pool')) . '/templates';
        self::assertFileExists($templates . '/INDEX.md');
        self::assertFileExists($templates . '/themenseite.md');
        self::assertLessThan(10240, (int) filesize($templates . '/INDEX.md'));

        $record = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile('knowledge-record-decision')));
        self::assertNotNull($record);
        $prerequisites = $record['fields']['prerequisites'] ?? [];
        self::assertIsArray($prerequisites);
        self::assertContains('foundation-working-principles', $prerequisites);
        self::assertNotContains('process-close', $prerequisites);
    }

    public function testThemenseiteTemplateHasFiveSectionsInOrder(): void
    {
        $content = (string) file_get_contents(
            dirname($this->skillFile('knowledge-maintain-pool')) . '/templates/themenseite.md',
        );

        preg_match_all('/^## .+$/m', $content, $matches);
        self::assertSame(
            ['## Stand', '## Entscheide', '## Fallen', '## Ersetzt', '## Verweise'],
            $matches[0],
        );

        foreach (['id:', 'description:', 'heimat:', 'ersetzt: []', 'schema_version: 1.0.0'] as $key) {
            self::assertStringContainsString($key, $content);
        }
    }

    public function testRecordDecisionNamesBothNoteFormsWithEnDash(): void
    {
        $content = (string) file_get_contents($this->skillFile('knowledge-record-decision'));

        self::assertStringContainsString('Wissen: <seite>#<abschnitt>', $content);
        self::assertStringContainsString("Wissen: keins \u{2013} <Grund>", $content);
        self::assertStringNotContainsString("Wissen: keins \u{2014}", $content);
        self::assertStringNotContainsString('Wissen: keins - ', $content);

        foreach (['stand', 'entscheide', 'fallen', 'ersetzt', 'verweise'] as $section) {
            self::assertStringContainsString('`' . $section . '`', $content);
        }
    }

    #[DataProvider('knowledgeSkills')]
    public function testKnowledgeDescriptionsHaveAtMostFortyFiveWords(string $name): void
    {
        $document = (new ParseSkillFrontmatter())((string) file_get_contents($this->skillFile($name)));
        self::assertNotNull($document);

        $description = $document['fields']['description'] ?? '';
        self::assertIsString($description);

        $words = preg_split('/\s+/', trim($description), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($words);
        self::assertNotEmpty($words);
        self::assertLessThanOrEqual(45, count($words), sprintf('Description of %s has %d words.', $name, count($words)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function knowledgeSkills(): array
    {
        $cases = [];
        foreach (self::KNOWLEDGE_SKILLS as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foundationSkills(): array
    {
        $cases = [];
        foreach (self::FOUNDATION_SKILLS as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    private function skillFile(string $name): string
    {
        return dirname(__DIR__, 4) . '/skills/' . $name . '/SKILL.md';
    }
}
