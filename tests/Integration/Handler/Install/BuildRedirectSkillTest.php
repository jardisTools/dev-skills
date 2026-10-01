<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\BuildRedirectSkill;
use JardisTools\DevSkills\Handler\Validate\ValidateSkillMd;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class BuildRedirectSkillTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-redirect-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testDescriptionIsTheFixedConstantForEveryOldName(): void
    {
        foreach (RenamedSkills::MAPPING as $old => $new) {
            $skillMd = $this->build($old);
            preg_match('/^description: (.*)$/m', $skillMd, $match);

            self::assertSame('Renamed to ' . $new . '. Load ' . $new . ' instead.', $match[1], $old);
            self::assertSame(sprintf(BuildRedirectSkill::DESCRIPTION, $new), $match[1], $old);
        }
    }

    public function testNameIsTheOldNameAndFolderCarriesIt(): void
    {
        $this->build('rules-architecture');

        self::assertFileExists($this->project->path('out/rules-architecture/SKILL.md'));
        self::assertStringContainsString(
            "---\nname: rules-architecture\n",
            (string) file_get_contents($this->project->path('out/rules-architecture/SKILL.md')),
        );
    }

    public function testBodyIsOneSentenceNamingTheNewSkill(): void
    {
        $skillMd = $this->build('do-git-branch');
        [, $body] = explode("\n---\n", $skillMd, 2);

        self::assertSame(
            "\n## Renamed\n\nThis skill was renamed to `git-start-branch`; load `git-start-branch` instead.\n",
            $body,
        );
    }

    public function testEveryRedirectPassesTheSkillValidator(): void
    {
        foreach (array_keys(RenamedSkills::MAPPING) as $old) {
            $this->build($old);
            self::assertSame([], (new ValidateSkillMd())($this->project->path('out/' . $old . '/SKILL.md')), $old);
        }
    }

    public function testFolderHoldsNothingButTheSkillFile(): void
    {
        $this->build('platform-usage');

        self::assertSame(['SKILL.md'], array_map('basename', glob($this->project->path('out/platform-usage/*')) ?: []));
    }

    public function testNameThatWasNeverRenamedIsRejected(): void
    {
        $this->expectException(InstallFailedException::class);

        (new BuildRedirectSkill(new Filesystem()))(
            new SkillDescriptor('git-foo', '', 'jardis/dev-skills'),
            $this->project->path('out/git-foo'),
        );
    }

    private function build(string $oldName): string
    {
        $destination = $this->project->path('out/' . $oldName);
        (new BuildRedirectSkill(new Filesystem()))(new SkillDescriptor($oldName, '', 'jardis/dev-skills'), $destination);

        return (string) file_get_contents($destination . '/SKILL.md');
    }
}
