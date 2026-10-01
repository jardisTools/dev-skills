<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Shell;

use JardisTools\DevSkills\Handler\Shell\ParseReviewerSource;
use JardisTools\DevSkills\Handler\Validate\ParseSkillFrontmatter;
use PHPUnit\Framework\TestCase;

final class ParseReviewerSourceTest extends TestCase
{
    public function testParsesNameDescriptionAndBody(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../../Fixture/Reviewers/security-reviewer.md');

        $source = $this->parse('security-reviewer', $content);

        self::assertNotNull($source);
        self::assertSame('security-reviewer', $source->role);
        self::assertSame('security-reviewer', $source->name);
        self::assertSame(
            'Reviews a change for injection paths, leaked secrets and unsafe file handling.',
            $source->description,
        );
        self::assertStringStartsWith('Check the change for injection paths', $source->body);
        self::assertStringEndsWith('a concrete fix.', $source->body);
    }

    public function testSourceWithoutValidFrontmatterYieldsNull(): void
    {
        self::assertNull($this->parse('alpha', "No frontmatter here.\n"));
        self::assertNull($this->parse('alpha', "---\nname: alpha\n---\nBody\n"), 'description missing');
        self::assertNull($this->parse('alpha', "---\ndescription: d\n---\nBody\n"), 'name missing');
        self::assertNull($this->parse('alpha', "---\nname: alpha\ndescription:\n---\nBody\n"), 'description empty');
        self::assertNull($this->parse('alpha', "---\nname: other\ndescription: d\n---\nBody\n"), 'name differs from file');
    }

    public function testRoleThatIsNoPlainNameYieldsNull(): void
    {
        foreach (['../evil', 'Upper', 'with space', '.hidden', ''] as $role) {
            self::assertNull($this->parse($role, "---\nname: {$role}\ndescription: d\n---\nBody\n"), $role);
        }
    }

    private function parse(string $role, string $content): ?\JardisTools\DevSkills\Data\ReviewerSource
    {
        return (new ParseReviewerSource((new ParseSkillFrontmatter())->__invoke(...)))($role, $content);
    }
}
