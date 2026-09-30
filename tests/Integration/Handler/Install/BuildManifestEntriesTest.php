<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\SelfSetEntry;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Data\StagedSkill;
use JardisTools\DevSkills\Handler\Install\BuildManifestEntries;
use JardisTools\DevSkills\Handler\Manifest\ChecksumDirectory;
use JardisTools\DevSkills\Handler\Manifest\ResolveManagedFolder;
use JardisTools\DevSkills\Tests\Support\TempProject;
use PHPUnit\Framework\TestCase;

final class BuildManifestEntriesTest extends TestCase
{
    private TempProject $project;
    private TempProject $foreign;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->foreign = new TempProject('dev-skills-foreign-');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
        $this->foreign->cleanup();
    }

    public function testValidPreviousEntryWithExistingFolderIsCarriedOver(): void
    {
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'alpha');
        $previous = $this->manifest(['.claude/skills/alpha', '.claude/skills/gone']);

        $manifest = $this->build($previous, []);

        self::assertSame(['.claude/skills/alpha'], array_keys($manifest->entries));
    }

    public function testAbsolutePreviousKeyIsNotCarriedIntoTheNewManifest(): void
    {
        $this->foreign->writeFile('victim/SKILL.md', 'v');
        $this->project->writeFile('.claude/skills/alpha/SKILL.md', 'alpha');
        $previous = $this->manifest([
            $this->foreign->path('victim'),
            $this->project->path('.claude/skills/alpha'),
        ]);

        $manifest = $this->build($previous, []);

        self::assertSame([], $manifest->entries);
    }

    public function testParentSegmentPreviousKeyIsNotCarriedIntoTheNewManifest(): void
    {
        $this->foreign->writeFile('victim/SKILL.md', 'v');
        $this->project->writeFile('docs/keep/SKILL.md', 'd');
        $previous = $this->manifest([
            '../' . basename($this->foreign->root) . '/victim',
            '.claude/skills/../../docs/keep',
        ]);

        $manifest = $this->build($previous, []);

        self::assertSame([], $manifest->entries);
    }

    public function testSymlinkPreviousKeyIsNotCarriedIntoTheNewManifest(): void
    {
        $this->foreign->writeFile('victim/SKILL.md', 'v');
        $this->project->mkdir('.claude/skills');
        self::assertTrue(symlink($this->foreign->path('victim'), $this->project->path('.claude/skills/alpha')));

        $manifest = $this->build($this->manifest(['.claude/skills/alpha']), []);

        self::assertSame([], $manifest->entries);
    }

    public function testInstalledSkillsKeepTheKeyStageSkillsBuiltAndGetTheCurrentChecksum(): void
    {
        $this->project->writeFile('.claude/skills/beta/SKILL.md', 'beta');
        $skill = new SkillDescriptor('beta', '/unused', 'jardis/dev-skills');
        $installed = [new StagedSkill(
            $skill,
            $this->project->path('.claude/skills/beta'),
            $this->project->path('.claude/skills/.jardis-staging-beta'),
            '.claude/skills/beta',
        )];

        $manifest = $this->build($this->manifest(['.claude/skills/../x']), $installed);

        self::assertSame(['.claude/skills/beta'], array_keys($manifest->entries));
        self::assertSame(
            (new ChecksumDirectory())($this->project->path('.claude/skills/beta')),
            $manifest->entries['.claude/skills/beta']['sha256'],
        );
        self::assertSame('jardis/dev-skills', $manifest->entries['.claude/skills/beta']['source']);
    }

    public function testCarriesSelfSetEntriesOverUnchanged(): void
    {
        $selfSet = ['CLAUDE.md' => new SelfSetEntry(true)];
        $previous = new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', [], $selfSet);

        $manifest = $this->build($previous, []);

        self::assertSame($selfSet, $manifest->selfSet);
        self::assertSame([], $this->build(null, [])->selfSet);
    }

    /**
     * @param list<string> $keys
     */
    private function manifest(array $keys): Manifest
    {
        $entries = [];
        foreach ($keys as $key) {
            $entries[$key] = ['source' => 'jardis/dev-skills', 'sha256' => 'old'];
        }

        return new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', $entries);
    }

    /**
     * @param list<StagedSkill> $installed
     */
    private function build(?Manifest $previous, array $installed): Manifest
    {
        return (new BuildManifestEntries(
            (new ChecksumDirectory())->__invoke(...),
            (new ResolveManagedFolder())->__invoke(...),
        ))($previous, $installed, $this->project->root, '1.4.0');
    }
}
