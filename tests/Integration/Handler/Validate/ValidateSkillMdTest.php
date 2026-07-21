<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Handler\Validate\ValidateSkillMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertContains("frontmatter: missing required field 'persona'", $errors);
        self::assertContains("frontmatter: missing required field 'prerequisites'", $errors);
        self::assertContains("frontmatter: missing required field 'next'", $errors);
    }

    public function testReportsInvalidZone(): void
    {
        $path = $this->writeSkill('bad-zone', $this->validSkill('bad-zone', 'invalid-zone-name'));

        $errors = (new ValidateSkillMd())($path);

        self::assertContains(
            "frontmatter: invalid zone 'invalid-zone-name', must be one of: crosscut, pre, post-reference, post-active, discovery",
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
        $longDescription = str_repeat('word ', 180);
        $path = $this->writeSkill('long-desc', $this->skillWithDescription('long-desc', 'crosscut', trim($longDescription)));

        $errors = (new ValidateSkillMd())($path);

        $this->assertSomeErrorMatches($errors, '/description has 180 words, max 175/');
    }

    public function testReportsBodyWithoutSectionHeading(): void
    {
        $content = "---\nname: bare\ndescription: A short description.\nzone: crosscut\npersona: C\nprerequisites: []\nnext: []\n---\n\n# only a top-level title\n\nplain prose, no section headings.\n";
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
        $body = str_repeat("a body line\n", 250);
        $content = $this->validFrontmatter('big', 'crosscut') . $body;
        $path = $this->writeSkill('big', $content);

        $errors = (new ValidateSkillMd())($path);

        $this->assertSomeErrorMatches($errors, '/length: \d+ lines exceeds budget of 225 for zone \'crosscut\'/');
    }

    public function testReportsLineBudgetViolationForDiscoveryZone(): void
    {
        // discovery zone shares the 150-line budget with crosscut (D1)
        $body = str_repeat("a catalog line\n", 200);
        $content = $this->validFrontmatter('big-discovery', 'discovery', 'X') . $body;
        $path = $this->writeSkill('big-discovery', $content);

        $errors = (new ValidateSkillMd())($path);

        $this->assertSomeErrorMatches($errors, '/length: \d+ lines exceeds budget of 150 for zone \'discovery\'/');
    }

    public function testAcceptsDiscoveryZoneWithinBudget(): void
    {
        $path = $this->writeSkill('within-budget', $this->validSkill('within-budget', 'discovery', 'X'));

        $errors = (new ValidateSkillMd())($path);

        self::assertSame([], $errors);
    }

    public function testReportsArrayFieldsNotBeingArrays(): void
    {
        $content = "---\nname: arrays\ndescription: A short description.\nzone: crosscut\npersona: C\nprerequisites: not-an-array\nnext: also-not\n---\n\n### 1. Topic\n\nBody.\n";
        $path = $this->writeSkill('arrays', $content);

        $errors = (new ValidateSkillMd())($path);

        self::assertContains("frontmatter: 'prerequisites' must be an array (use [] for empty)", $errors);
        self::assertContains("frontmatter: 'next' must be an array (use [] for empty)", $errors);
    }

    public function testReportsInvalidPersonaValue(): void
    {
        $path = $this->writeSkill('bad-persona', $this->validSkill('bad-persona', 'crosscut', 'E'));

        $errors = (new ValidateSkillMd())($path);

        self::assertContains(
            "frontmatter: invalid persona 'E', must be one of: A, C, D, X",
            $errors,
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function allowedPersonasProvider(): array
    {
        return [['A'], ['C'], ['D'], ['X']];
    }

    #[DataProvider('allowedPersonasProvider')]
    public function testAcceptsAllowedPersonaValues(string $persona): void
    {
        $path = $this->writeSkill('persona-' . strtolower($persona), $this->validSkill('persona-' . strtolower($persona), 'crosscut', $persona));

        $errors = (new ValidateSkillMd())($path);

        self::assertSame([], $errors);
    }

    public function testReportsForbiddenGeneratorTokenInBody(): void
    {
        $content = $this->validFrontmatter('forbidden', 'crosscut')
            . "### 1. Topic\n\nThe Generator delegates to PHPRenderer for the actual output.\n\n### 2. Reference\n\n- ref.\n";
        $path = $this->writeSkill('forbidden', $content);

        $errors = (new ValidateSkillMd())($path);

        $this->assertSomeErrorMatches(
            $errors,
            "/forbidden Generator-Internas token 'PHPRenderer'/",
        );
    }

    public function testReportsForbiddenGoFileReferenceInBody(): void
    {
        $content = $this->validFrontmatter('go-ref', 'crosscut')
            . "### 1. Topic\n\nSee command_handler.go:550 for the Create-Setdata path.\n\n### 2. Reference\n\n- ref.\n";
        $path = $this->writeSkill('go-ref', $content);

        $errors = (new ValidateSkillMd())($path);

        $this->assertSomeErrorMatches(
            $errors,
            "/forbidden Generator-Internas token 'command_handler\\.go:550'/",
        );
    }

    public function testAllowsForbiddenTokenInAnchorsSection(): void
    {
        $content = $this->validFrontmatter('anchors-ok', 'crosscut')
            . "### 1. Topic\n\nClean body, no forbidden tokens here.\n\n### 2. Anchors\n\n- Builder internals (Persona E only): see PHPRenderer + internal/builder/ for the full pipeline.\n";
        $path = $this->writeSkill('anchors-ok', $content);

        $errors = (new ValidateSkillMd())($path);

        self::assertSame([], $errors);
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

    private function validFrontmatter(string $name, string $zone, string $persona = 'C'): string
    {
        return sprintf(
            "---\nname: %s\ndescription: A valid short description for tests.\nzone: %s\npersona: %s\nprerequisites: []\nnext: []\n---\n\n",
            $name,
            $zone,
            $persona,
        );
    }

    private function validSkill(string $name, string $zone, string $persona = 'C'): string
    {
        return $this->validFrontmatter($name, $zone, $persona)
            . "### 1. Topic\n\nBody.\n\n### 2. Reference\n\n- ref.\n";
    }

    private function skillWithDescription(string $name, string $zone, string $description): string
    {
        $fm = sprintf(
            "---\nname: %s\ndescription: %s\nzone: %s\npersona: C\nprerequisites: []\nnext: []\n---\n\n",
            $name,
            $description,
            $zone,
        );
        return $fm . "### 1. Topic\n\nBody.\n\n### 2. Reference\n\n- ref.\n";
    }
}
