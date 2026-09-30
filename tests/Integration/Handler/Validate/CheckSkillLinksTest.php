<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Validate;

use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Handler\Validate\CheckSkillLinks;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class CheckSkillLinksTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->writeSkill('foundation-architecture', '[]', '[]');
        $this->writeSkill('git-commit-change', '[]', '[]');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testLinksToExistingSkillFoldersPass(): void
    {
        $path = $this->writeSkill('git-start-branch', '[foundation-architecture]', '[git-commit-change]');

        self::assertSame([], (new CheckSkillLinks())($path));
    }

    public function testEmptyLinksPass(): void
    {
        $path = $this->writeSkill('git-start-branch', '[]', '[]');

        self::assertSame([], (new CheckSkillLinks())($path));
    }

    public function testRetiredNameIsReportedWithItsReplacement(): void
    {
        $path = $this->writeSkill('git-start-branch', '[rules-architecture]', '[do-git-commit]');

        $errors = (new CheckSkillLinks())($path);

        self::assertSame([
            "prerequisites entry 'rules-architecture' is a retired skill name, use 'foundation-architecture'",
            "next entry 'do-git-commit' is a retired skill name, use 'git-commit-change'",
        ], $errors);
    }

    public function testEveryRetiredNameIsRejected(): void
    {
        foreach (array_keys(RenamedSkills::MAPPING) as $old) {
            // the retired name must fail even when a redirect folder of that name exists
            $this->writeSkill($old, '[]', '[]');
            $path = $this->writeSkill('git-start-branch', '[]', '[' . $old . ']');

            $errors = (new CheckSkillLinks())($path);

            self::assertCount(1, $errors, $old);
            self::assertStringContainsString('retired skill name', $errors[0]);
        }
    }

    public function testUnknownNameIsReported(): void
    {
        $path = $this->writeSkill('git-start-branch', '[does-not-exist]', '[]');

        $errors = (new CheckSkillLinks())($path);

        self::assertSame(["prerequisites entry 'does-not-exist' does not match any skill folder"], $errors);
    }

    public function testFolderWithoutSkillMdIsNotASkill(): void
    {
        $this->project->mkdir('skills/empty-folder');
        $path = $this->writeSkill('git-start-branch', '[]', '[empty-folder]');

        self::assertSame(["next entry 'empty-folder' does not match any skill folder"], (new CheckSkillLinks())($path));
    }

    public function testPathTraversalIsNotAValidLink(): void
    {
        $path = $this->writeSkill('git-start-branch', '[]', '[../skills/git-commit-change]');

        self::assertCount(1, (new CheckSkillLinks())($path));
    }

    public function testReportsMissingFileAndMissingFrontmatter(): void
    {
        self::assertStringContainsString(
            'file does not exist',
            (new CheckSkillLinks())($this->project->path('skills/none/SKILL.md'))[0],
        );

        $path = $this->project->writeFile('skills/no-fm/SKILL.md', "# no frontmatter\n");
        self::assertStringContainsString('frontmatter not found', (new CheckSkillLinks())($path)[0]);
    }

    private function writeSkill(string $name, string $prerequisites, string $next): string
    {
        return $this->project->writeFile(
            'skills/' . $name . '/SKILL.md',
            "---\nname: {$name}\ndescription: A valid short description for tests.\nzone: crosscut\npersona: C\n"
            . "prerequisites: {$prerequisites}\nnext: {$next}\n---\n\n### 1. Topic\n\nBody.\n",
        );
    }
}
