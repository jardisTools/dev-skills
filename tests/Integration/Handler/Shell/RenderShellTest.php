<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Shell;

use JardisTools\DevSkills\Data\ReviewerSource;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Handler\Shell\EncodeTomlBasicString;
use JardisTools\DevSkills\Handler\Shell\EncodeTomlMultiline;
use JardisTools\DevSkills\Handler\Validate\ParseSkillFrontmatter;
use JardisTools\DevSkills\Tests\Support\AddonFactory;
use JardisTools\DevSkills\Tests\Support\MiniToml;
use PHPUnit\Framework\TestCase;

final class RenderShellTest extends TestCase
{
    private const HOME_PATH_REGEX = '#/(Users|home)/[^/\s]+/#';
    private const POINTER = 'Read `.claude/skills/process-review-board/reviewers/test-reviewer.md` and follow it'
        . ' as your complete review instructions.';

    public function testEachFormatUsesPathAndHeaderFromR8Table(): void
    {
        $source = $this->source('test-reviewer');

        self::assertSame(
            [
                'Claude' => '.claude/agents/test-reviewer.md',
                'Codex' => '.codex/agents/test-reviewer.toml',
                'Cursor' => '.cursor/agents/test-reviewer.md',
                'Copilot' => '.github/agents/test-reviewer.agent.md',
                'Gemini' => '.gemini/agents/test-reviewer.md',
            ],
            array_combine(
                array_map(static fn (ShellFormat $f): string => $f->name, ShellFormat::cases()),
                array_map(static fn (ShellFormat $f): string => $f->pathFor('test-reviewer'), ShellFormat::cases()),
            ),
        );

        $codex = $this->render($source, ShellFormat::Codex);
        self::assertStringStartsWith("name = \"test-reviewer\"\ndescription = \"", $codex);
        self::assertStringContainsString("\ndeveloper_instructions = '''\n", $codex);
        foreach ([ShellFormat::Claude, ShellFormat::Cursor, ShellFormat::Copilot, ShellFormat::Gemini] as $format) {
            self::assertStringStartsWith(
                "---\nname: test-reviewer\ndescription: \"Checks that tests exist: one behaviour per test,"
                . " no weakened assertions.\"\n---\n\n",
                $this->render($source, $format),
                $format->name,
            );
        }
    }

    public function testFrontmatterShellsParseWithRequiredFields(): void
    {
        $source = $this->source('security-reviewer');

        foreach ([ShellFormat::Claude, ShellFormat::Cursor, ShellFormat::Copilot, ShellFormat::Gemini] as $format) {
            $parsed = (new ParseSkillFrontmatter())($this->render($source, $format));

            self::assertNotNull($parsed, $format->name);
            self::assertSame('security-reviewer', $parsed['fields']['name'] ?? null, $format->name);
            self::assertSame($source->description, $parsed['fields']['description'] ?? null, $format->name);
        }
    }

    public function testCodexShellParsesWithMiniTomlAndHasRequiredFields(): void
    {
        $source = $this->source('security-reviewer');

        $values = MiniToml::parse($this->render($source, ShellFormat::Codex));

        self::assertSame(['name', 'description', 'developer_instructions'], array_keys($values));
        self::assertSame('security-reviewer', $values['name']);
        self::assertSame($source->description, $values['description']);
        self::assertNotSame('', trim($values['developer_instructions']));
    }

    public function testDescriptionWithTripleSingleQuoteYieldsValidToml(): void
    {
        $description = "Flags '''odd''' quoting, \"double\" quotes and a back\\slash.";
        $source = new ReviewerSource('odd-reviewer', 'odd-reviewer', $description, 'Body');

        $values = MiniToml::parse($this->render($source, ShellFormat::Codex));

        self::assertSame($description, $values['description']);
    }

    public function testMultilineTextWithTripleSingleQuoteUsesTheEscapedForm(): void
    {
        $encode = new EncodeTomlMultiline((new EncodeTomlBasicString())->__invoke(...));
        $text = "First '''line'''\nsecond \"line\" with a back\\slash\tand a tab";

        $encoded = $encode($text);

        self::assertStringStartsWith('"""' . "\n", $encoded);
        self::assertSame($text . "\n", MiniToml::parse('v = ' . $encoded . "\n")['v']);
    }

    public function testModelFieldIsNeverWritten(): void
    {
        $source = $this->source('test-reviewer');

        foreach (ShellFormat::cases() as $format) {
            self::assertDoesNotMatchRegularExpression(
                '/^\s*model\s*[:=]/mi',
                $this->render($source, $format),
                $format->name,
            );
        }
    }

    public function testBodyIsOneReferenceSentenceToTheSource(): void
    {
        $source = $this->source('test-reviewer');

        foreach ([ShellFormat::Claude, ShellFormat::Cursor, ShellFormat::Copilot, ShellFormat::Gemini] as $format) {
            $parsed = (new ParseSkillFrontmatter())($this->render($source, $format));

            self::assertSame(self::POINTER, trim($parsed['body'] ?? ''), $format->name);
        }
        $values = MiniToml::parse($this->render($source, ShellFormat::Codex));
        self::assertSame(self::POINTER, trim($values['developer_instructions']));
        self::assertStringNotContainsString($source->body, $this->render($source, ShellFormat::Claude));
    }

    public function testRenderedShellsContainNoHomePaths(): void
    {
        foreach (['security-reviewer', 'test-reviewer'] as $role) {
            foreach (ShellFormat::cases() as $format) {
                self::assertDoesNotMatchRegularExpression(
                    self::HOME_PATH_REGEX,
                    $this->render($this->source($role), $format),
                    $role . ' ' . $format->name,
                );
            }
        }
    }

    public function testShellPathsAreRecognisedAsShellPaths(): void
    {
        foreach (ShellFormat::cases() as $format) {
            self::assertSame($format, ShellFormat::fromPath($format->pathFor('some-role')));
        }
        foreach (['CLAUDE.md', '.claude/agents/../x.md', '.claude/agents/Up.md', '.claude/agents/sub/x.md', '.codex/agents/x.md'] as $path) {
            self::assertNull(ShellFormat::fromPath($path), $path);
        }
    }

    private function render(ReviewerSource $source, ShellFormat $format): string
    {
        return AddonFactory::renderShell()($source, $format);
    }

    private function source(string $role): ReviewerSource
    {
        $content = (string) file_get_contents(__DIR__ . '/../../../Fixture/Reviewers/' . $role . '.md');
        $source = AddonFactory::parseReviewerSource()($role, $content);
        self::assertNotNull($source);

        return $source;
    }
}
