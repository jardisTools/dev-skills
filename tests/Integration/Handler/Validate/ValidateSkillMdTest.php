<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Handler\Validate\ValidateSkillMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class ValidateSkillMdTest extends TestCase
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

    public function testValidSkillReturnsNoErrors(): void
    {
        $path = $this->writeSkill('demo-skill', $this->validSkill('demo-skill', 'crosscut'));

        $errors = (new ValidateSkillMd())($path);

        self::assertSame([], $errors);
    }

    public function testReportsMissingFile(): void
    {
        $errors = (new ValidateSkillMd())($this->project->path('skills/missing/SKILL.md'));

        self::assertCount(1, $errors);
        self::assertStringContainsString('file does not exist', $errors[0]);
    }

    public function testReportsMissingFrontmatter(): void
    {
        $path = $this->writeSkill('no-fm', "# just a heading\n\nbody only\n");

        $errors = (new ValidateSkillMd())($path);

        self::assertCount(1, $errors);
        self::assertStringContainsString('frontmatter not found', $errors[0]);
    }

    public function testReportsMissingRequiredFrontmatterFields(): void
    {
        $path = $this->writeSkill('partial', "---\nname: partial\n---\nbody\n");

        $errors = (new ValidateSkillMd())($path);

        self::assertContains("frontmatter: missing required field 'description'", $errors);
        self::assertContains("frontmatter: missing required field 'zone'", $errors);
        self::assertContains("frontmatter: missing required field 'prerequisites'", $errors);
        self::assertContains("frontmatter: missing required field 'next'", $errors);
    }

    public function testReportsInvalidZone(): void
    {
        $path = $this->writeSkill('bad-zone', $this->validSkill('bad-zone', 'invalid-zone-name'));

        $errors = (new ValidateSkillMd())($path);

        self::assertContains(
            "frontmatter: invalid zone 'invalid-zone-name', must be one of: crosscut, pre, post-reference, post-active",
            $errors,
        );
    }

    public function testReportsNameMismatchWithDirectory(): void
    {
        $path = $this->writeSkill('actual-dirname', $this->validSkill('wrong-name', 'crosscut'));

        $errors = (new ValidateSkillMd())($path);

        self::assertContains(
            "frontmatter: name 'wrong-name' must match directory name 'actual-dirname'",
            $errors,
        );
    }

    public function testReportsNonKebabCaseName(): void
    {
        $path = $this->writeSkill('CamelCase', $this->validSkill('CamelCase', 'crosscut'));

        $errors = (new ValidateSkillMd())($path);

        self::assertContains(
            "frontmatter: name 'CamelCase' must be kebab-case (lowercase letters, dashes)",
            $errors,
        );
    }

    public function testReportsDescriptionOverWordLimit(): void
    {
        $longDescription = str_repeat('word ', 65);
        $path = $this->writeSkill('long-desc', $this->skillWithDescription('long-desc', 'crosscut', trim($longDescription)));

        $errors = (new ValidateSkillMd())($path);

        $this->assertSomeErrorMatches($errors, '/description has 65 words, max 60/');
    }

    public function testReportsBodyWithoutSectionHeading(): void
    {
        $content = "---\nname: bare\ndescription: A short description.\nzone: crosscut\nprerequisites: []\nnext: []\n---\n\n# only a top-level title\n\nplain prose, no section headings.\n";
        $path = $this->writeSkill('bare', $content);

        $errors = (new ValidateSkillMd())($path);

        self::assertContains("body: must contain at least one '##' or '###' section heading", $errors);
    }

    public function testAcceptsBodyWithNumberedSectionHeadings(): void
    {
        // Represents the actual shape of the shipped bundle skills (`### 1. Topic`).
        $content = $this->validFrontmatter('numbered', 'crosscut')
            . "### 1. First topic\n\nBody.\n\n### 2. Second topic\n\nMore body.\n";
        $path = $this->writeSkill('numbered', $content);

        $errors = (new ValidateSkillMd())($path);

        self::assertSame([], $errors);
    }

    public function testReportsLineBudgetViolation(): void
    {
        $body = str_repeat("a body line\n", 200);
        $content = $this->validFrontmatter('big', 'crosscut') . $body;
        $path = $this->writeSkill('big', $content);

        $errors = (new ValidateSkillMd())($path);

        $this->assertSomeErrorMatches($errors, '/length: \d+ lines exceeds budget of 150 for zone \'crosscut\'/');
    }

    public function testReportsArrayFieldsNotBeingArrays(): void
    {
        $content = "---\nname: arrays\ndescription: A short description.\nzone: crosscut\nprerequisites: not-an-array\nnext: also-not\n---\n\n### 1. Topic\n\nBody.\n";
        $path = $this->writeSkill('arrays', $content);

        $errors = (new ValidateSkillMd())($path);

        self::assertContains("frontmatter: 'prerequisites' must be an array (use [] for empty)", $errors);
        self::assertContains("frontmatter: 'next' must be an array (use [] for empty)", $errors);
    }

    public function testAllBundledSkillsPassValidation(): void
    {
        $skillsRoot = (string) realpath(__DIR__ . '/../../../../skills');
        self::assertNotSame('', $skillsRoot, 'Could not locate skills/ directory.');

        $skillFiles = glob($skillsRoot . '/*/SKILL.md');
        self::assertNotEmpty($skillFiles, 'No bundled SKILL.md files found.');

        $validator   = new ValidateSkillMd();
        $allErrors   = [];
        foreach ($skillFiles as $file) {
            $errors = $validator($file);
            if ($errors !== []) {
                $allErrors[basename(dirname($file))] = $errors;
            }
        }

        self::assertSame([], $allErrors, "Bundled skills failed validation:\n" . print_r($allErrors, true));
    }

    /**
     * @param list<string> $errors
     */
    private function assertSomeErrorMatches(array $errors, string $pattern): void
    {
        foreach ($errors as $err) {
            if (preg_match($pattern, $err) === 1) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail(sprintf("No error matched pattern %s. Errors:\n%s", $pattern, implode("\n", $errors)));
    }

    private function writeSkill(string $dirName, string $content): string
    {
        return $this->project->writeFile('skills/' . $dirName . '/SKILL.md', $content);
    }

    private function validFrontmatter(string $name, string $zone): string
    {
        return sprintf(
            "---\nname: %s\ndescription: A valid short description for tests.\nzone: %s\nprerequisites: []\nnext: []\n---\n\n",
            $name,
            $zone,
        );
    }

    private function validSkill(string $name, string $zone): string
    {
        return $this->validFrontmatter($name, $zone)
            . "### 1. Topic\n\nBody.\n\n### 2. Reference\n\n- ref.\n";
    }

    private function skillWithDescription(string $name, string $zone, string $description): string
    {
        $fm = sprintf(
            "---\nname: %s\ndescription: %s\nzone: %s\nprerequisites: []\nnext: []\n---\n\n",
            $name,
            $description,
            $zone,
        );
        return $fm . "### 1. Topic\n\nBody.\n\n### 2. Reference\n\n- ref.\n";
    }
}
