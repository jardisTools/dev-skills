<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\ExpandLegacyGlobs;
use JardisTools\DevSkills\Handler\Install\FilterBundledSkills;
use JardisTools\DevSkills\Handler\Install\IsMandatorySkill;
use JardisTools\DevSkills\Handler\Install\SelectRedirects;
use JardisTools\DevSkills\Handler\Manifest\ResolveManagedFolder;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use JardisTools\DevSkills\Tests\Support\TreeSnapshot;
use PHPUnit\Framework\TestCase;

final class SelectRedirectsTest extends TestCase
{
    private const SUM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-redirects-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testNoPreviousStateMeansNoRedirect(): void
    {
        self::assertSame([], $this->select(null, $this->selected(['foundation-architecture'])));
    }

    public function testManagedOldFolderWithSelectedNewNameGetsARedirect(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture', 'rules-testing']);
        $previous = $this->previous(['.claude/skills/rules-architecture', '.claude/skills/rules-testing']);

        $redirects = $this->select($previous, $this->selected(['foundation-architecture', 'foundation-testing']));

        self::assertSame(['rules-architecture', 'rules-testing'], $this->names($redirects));
        self::assertSame('', $redirects[0]->sourceDir);
    }

    public function testDeselectedNewNameGetsNoRedirect(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture', 'rules-testing']);
        $previous = $this->previous(['.claude/skills/rules-architecture', '.claude/skills/rules-testing']);

        $redirects = $this->select($previous, $this->selected(['foundation-testing']));

        self::assertSame(['rules-testing'], $this->names($redirects));
    }

    public function testOldNameWithoutEntryOrWithoutFolderGetsNoRedirect(): void
    {
        // do-git-push: folder exists but is the user's (no entry); rules-patterns: entry but folder deleted.
        LegacyFixture::writeInstalledBundle($this->project, ['do-git-push']);
        $previous = $this->previous(['.claude/skills/rules-patterns']);

        $redirects = $this->select($previous, $this->selected(['git-push-and-open-pr', 'foundation-patterns']));

        self::assertSame([], $redirects);
    }

    public function testEntriesOfOtherSourcesAndOtherFoldersAreIgnored(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture']);
        $this->project->writeFile('.agents/skills/rules-architecture/SKILL.md', 'x');
        $previous = new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', [
            '.claude/skills/rules-architecture' => ['source' => 'jardisadapter/cache', 'sha256' => self::SUM],
            '.agents/skills/rules-architecture' => ['source' => 'jardis/dev-skills', 'sha256' => self::SUM],
        ]);

        self::assertSame([], $this->select($previous, $this->selected(['foundation-architecture'])));
    }

    public function testOldFolderThatIsASymlinkGetsNoRedirectAndStaysUntouched(): void
    {
        $foreign = new TempProject('dev-skills-redirects-foreign-');
        try {
            $foreign->writeFile('victim/SKILL.md', 'victim');
            $this->project->mkdir('.claude/skills');
            $link = $this->project->path('.claude/skills/rules-architecture');
            self::assertTrue(symlink($foreign->path('victim'), $link));
            $before = TreeSnapshot::of($foreign->root);
            $previous = $this->previous(['.claude/skills/rules-architecture']);

            $redirects = $this->select($previous, $this->selected(['foundation-architecture']));

            self::assertSame([], $redirects);
            self::assertTrue(is_link($link));
            self::assertSame($foreign->path('victim'), readlink($link));
            self::assertSame($before, TreeSnapshot::of($foreign->root));
        } finally {
            $foreign->cleanup();
        }
    }

    public function testEveryOldNameCanBeRedirectedToItsNewName(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);
        $previous = $this->previous(array_map(
            static fn (string $old): string => '.claude/skills/' . $old,
            array_keys(RenamedSkills::MAPPING),
        ));

        $redirects = $this->select($previous, $this->selected(array_values(RenamedSkills::MAPPING)));

        self::assertSame(array_keys(RenamedSkills::MAPPING), $this->names($redirects));
    }

    public function testConfigFalseGivesNoRedirectEvenThoughTheMandatoryGroupIsInstalled(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture']);
        $previous = $this->previous(['.claude/skills/rules-architecture']);

        $redirects = $this->select($previous, $this->selected(['foundation-architecture']), PluginConfig::onlyMandatory());

        self::assertSame([], $redirects);
    }

    public function testListConfigGivesRedirectsForTheOldNamesItMatchesOnly(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture', 'do-git-push']);
        $previous = $this->previous(['.claude/skills/rules-architecture', '.claude/skills/do-git-push']);
        $selected = $this->selected(['foundation-architecture', 'git-push-and-open-pr']);

        $redirects = $this->select($previous, $selected, PluginConfig::filtered(['rules-*'], []));

        self::assertSame(['rules-architecture'], $this->names($redirects));
    }

    public function testExcludedOldNameGetsNoRedirect(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture', 'do-git-push']);
        $previous = $this->previous(['.claude/skills/rules-architecture', '.claude/skills/do-git-push']);
        $selected = $this->selected(['foundation-architecture', 'git-push-and-open-pr']);

        $redirects = $this->select($previous, $selected, PluginConfig::filtered([], ['rules-*']));

        self::assertSame(['do-git-push'], $this->names($redirects));
    }

    /**
     * @param list<SkillDescriptor> $selected
     * @return list<SkillDescriptor>
     */
    private function select(?Manifest $previous, array $selected, ?PluginConfig $config = null): array
    {
        $filter = (new FilterBundledSkills(
            (new IsMandatorySkill())->__invoke(...),
            (new ExpandLegacyGlobs())->__invoke(...),
        ))->__invoke(...);

        return (new SelectRedirects($filter, (new ResolveManagedFolder())->__invoke(...)))($previous, $selected, $config ?? PluginConfig::all(), $this->project->root);
    }

    /**
     * @param list<string> $keys
     */
    private function previous(array $keys): Manifest
    {
        $entries = [];
        foreach ($keys as $key) {
            $entries[$key] = ['source' => 'jardis/dev-skills', 'sha256' => self::SUM];
        }

        return new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', $entries);
    }

    /**
     * @param list<string> $names
     * @return list<SkillDescriptor>
     */
    private function selected(array $names): array
    {
        return array_map(
            static fn (string $name): SkillDescriptor => new SkillDescriptor($name, '/x/' . $name, 'jardis/dev-skills'),
            $names,
        );
    }

    /**
     * @param list<SkillDescriptor> $skills
     * @return list<string>
     */
    private function names(array $skills): array
    {
        return array_map(static fn (SkillDescriptor $s): string => $s->name, $skills);
    }
}
