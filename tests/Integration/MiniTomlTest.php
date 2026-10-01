<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration;

use JardisTools\DevSkills\Tests\Support\MiniToml;
use PHPUnit\Framework\TestCase;

final class MiniTomlTest extends TestCase
{
    /**
     * The fixture is the first example of the Codex documentation, not written by the plugin's own
     * writer: what the parser accepts here does not depend on the code it is meant to check (T17).
     */
    public function testParsesCodexDocExample(): void
    {
        $toml = (string) file_get_contents(__DIR__ . '/../Fixture/Reviewers/codex-doc-example.toml');

        $values = MiniToml::parse($toml);

        self::assertSame(
            ['name', 'description', 'model', 'model_reasoning_effort', 'sandbox_mode', 'developer_instructions'],
            array_keys($values),
        );
        self::assertSame('pr_explorer', $values['name']);
        self::assertSame(
            'Read-only codebase explorer for gathering evidence before changes are proposed.',
            $values['description'],
        );
        self::assertSame(
            "Stay in exploration mode.\n"
            . 'Trace the real execution path, cite files and symbols, and avoid proposing fixes unless the parent'
            . " agent asks for them.\n"
            . "Prefer fast search and targeted file reads over broad scans.\n",
            $values['developer_instructions'],
        );
    }

    public function testParsesLiteralFormsAndEscapes(): void
    {
        $values = MiniToml::parse(
            "a = 'C:\\path'\nb = \"tab\\there \\\"q\\\" \\u00E4\"\nc = '''\nline \"\"\" one\nline two\n'''\n"
            . "d = \"\"\"\nx\\\\y \\\"\\\"\\\" z\n\"\"\"  # trailing comment\n",
        );

        self::assertSame('C:\\path', $values['a']);
        self::assertSame("tab\there \"q\" \u{E4}", $values['b']);
        self::assertSame("line \"\"\" one\nline two\n", $values['c']);
        self::assertSame("x\\y \"\"\" z\n", $values['d']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDocuments')]
    public function testRejectsWhatItDoesNotKnow(string $toml): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MiniToml::parse($toml);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedDocuments(): array
    {
        return [
            'unterminated string' => ["a = \"open\n"],
            'unterminated multi-line' => ["a = '''\nopen\n"],
            'duplicate key' => ["a = \"1\"\na = \"2\"\n"],
            'table header' => ["[table]\n"],
            'number value' => ["a = 1\n"],
            'text after value' => ["a = \"x\" y\n"],
            'unknown escape' => ["a = \"\\q\"\n"],
        ];
    }
}
