<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Handler\Install\BuildLegacyManifest;
use JardisTools\DevSkills\Handler\Manifest\ResolveManagedFolder;
use JardisTools\DevSkills\Tests\Support\LegacyFixture;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class BuildLegacyManifestTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject('dev-skills-legacy-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function testMissingManifestListsExactlyTheExistingOldNamesOfTheFixedList(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture', 'do-git-push']);
        foreach (['git-foo', 'design-mine', 'do-mine', 'foundation-architecture'] as $name) {
            $this->project->writeFile('.claude/skills/' . $name . '/SKILL.md', $name);
        }
        $this->project->writeFile('.agents/skills/rules-testing/SKILL.md', '1.3.x never wrote here');

        $legacy = $this->build(new ManifestReadResult(ManifestState::Missing));

        self::assertNotNull($legacy);
        self::assertSame(
            ['.claude/skills/rules-architecture', '.claude/skills/do-git-push'],
            array_keys($legacy->entries),
        );
        foreach ($legacy->entries as $entry) {
            self::assertSame(BuildLegacyManifest::UNKNOWN_CHECKSUM, $entry['sha256']);
        }
    }

    public function testAllEighteenOldNamesAreCoveredByTheFixedList(): void
    {
        LegacyFixture::writeInstalledBundle($this->project);

        $legacy = $this->build(new ManifestReadResult(ManifestState::Missing));

        self::assertNotNull($legacy);
        self::assertCount(count(RenamedSkills::MAPPING), $legacy->entries);
    }

    public function testNoOldFolderMeansNoLegacyManifest(): void
    {
        $this->project->writeFile('.claude/skills/git-foo/SKILL.md', 'mine');

        self::assertNull($this->build(new ManifestReadResult(ManifestState::Missing)));
    }

    public function testOnlyAMissingManifestIsReplaced(): void
    {
        LegacyFixture::writeInstalledBundle($this->project, ['rules-architecture']);

        self::assertNull($this->build(new ManifestReadResult(ManifestState::Defective, null, 'x')));
        self::assertNull($this->build(new ManifestReadResult(ManifestState::TooNew, new Manifest(2, '9.9.9'), 'y')));
        self::assertNull($this->build(new ManifestReadResult(
            ManifestState::Healthy,
            new Manifest(Manifest::SCHEMA_VERSION, '1.4.0'),
        )));
    }

    public function testASymlinkedOldFolderIsNotListed(): void
    {
        $this->project->writeFile('elsewhere/SKILL.md', 'x');
        $this->project->mkdir('.claude/skills');
        symlink($this->project->path('elsewhere'), $this->project->path('.claude/skills/rules-architecture'));

        self::assertNull($this->build(new ManifestReadResult(ManifestState::Missing)));
    }

    private function build(ManifestReadResult $read): ?Manifest
    {
        return (new BuildLegacyManifest((new ResolveManagedFolder())->__invoke(...)))($read, $this->project->root);
    }
}
