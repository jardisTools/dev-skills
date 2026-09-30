<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Install\CommitStagedSkills;
use JardisTools\DevSkills\Handler\Install\CopySkill;
use JardisTools\DevSkills\Handler\Install\StageSkills;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class StageSkillsTest extends TestCase
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

    public function testStagesBesideEveryTargetWithoutTouchingTheTargets(): void
    {
        $this->project->writeFile('source/alpha/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'old');

        $staged = $this->stage([$this->skill('alpha')], ['.claude/skills', '.agents/skills']);

        self::assertCount(2, $staged);
        self::assertSame('.claude/skills/alpha', $staged[0]->manifestKey);
        self::assertSame('.agents/skills/alpha', $staged[1]->manifestKey);
        self::assertSame('new', file_get_contents($staged[0]->stagingDir . '/SKILL.md'));
        self::assertSame(dirname($staged[0]->targetDir), dirname($staged[0]->stagingDir));
        self::assertSame('old', file_get_contents($staged[0]->targetDir . '/SKILL.md'));
        self::assertDirectoryDoesNotExist($staged[1]->targetDir);
    }

    public function testFailureRemovesEveryStagingDirectoryAndLeavesTargetsAlone(): void
    {
        $this->project->writeFile('source/alpha/SKILL.md', 'new-alpha');
        $this->project->writeFile('source/beta/SKILL.md', 'new-beta');
        // Real obstacle: a dangling link cannot be copied, also when running as root.
        symlink($this->project->path('source/none'), $this->project->path('source/beta/broken'));
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'old-alpha');

        try {
            $this->stage([$this->skill('alpha'), $this->skill('beta')], ['.claude/skills']);
            self::fail('Staging must fail on the uncopyable entry.');
        } catch (InstallFailedException) {
            self::assertSame(['alpha'], $this->entriesOf('.claude/skills'));
            self::assertSame('old-alpha', file_get_contents($this->project->path('.claude/skills/alpha/SKILL.md')));
        }
    }

    public function testStaleStagingDirectoryFromAbortedRunIsReplaced(): void
    {
        $this->project->writeFile('source/alpha/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/.jardis-staging-alpha/leftover.md', 'stale');

        $staged = $this->stage([$this->skill('alpha')], ['.claude/skills']);

        self::assertFileDoesNotExist($staged[0]->stagingDir . '/leftover.md');
        self::assertFileExists($staged[0]->stagingDir . '/SKILL.md');
    }

    public function testCommitSwapsStagedIntoPlaceAndLeavesNothingBehind(): void
    {
        $this->project->writeFile('source/alpha/SKILL.md', 'new');
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'old');
        $this->project->writeFile('.claude/skills/alpha/local-only.md', 'gone after swap');

        $staged = $this->stage([$this->skill('alpha')], ['.claude/skills', '.agents/skills']);
        (new CommitStagedSkills(new Filesystem()))($staged);

        self::assertSame('new', file_get_contents($this->project->path('.claude/skills/alpha/SKILL.md')));
        self::assertFileDoesNotExist($this->project->path('.claude/skills/alpha/local-only.md'));
        self::assertSame('new', file_get_contents($this->project->path('.agents/skills/alpha/SKILL.md')));
        self::assertSame(['alpha'], $this->entriesOf('.claude/skills'));
        self::assertSame(['alpha'], $this->entriesOf('.agents/skills'));
    }

    /**
     * @param list<SkillDescriptor> $skills
     * @param list<string>          $targets project-relative skill roots
     * @return list<\JardisTools\DevSkills\Data\StagedSkill>
     */
    private function stage(array $skills, array $targets): array
    {
        $absolute = [];
        foreach ($targets as $target) {
            $absolute[] = $this->project->mkdir($target);
        }
        $fs = new Filesystem();

        return (new StageSkills($fs, (new CopySkill($fs))->__invoke(...)))(
            $skills,
            array_map(static fn (string $t): string => (string) realpath($t), $absolute),
            $this->project->root,
        );
    }

    private function skill(string $name): SkillDescriptor
    {
        return new SkillDescriptor($name, $this->project->path('source/' . $name), 'jardisadapter/cache');
    }

    /**
     * @return list<string>
     */
    private function entriesOf(string $relative): array
    {
        $entries = array_values(array_diff(scandir($this->project->path($relative)) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }
}
