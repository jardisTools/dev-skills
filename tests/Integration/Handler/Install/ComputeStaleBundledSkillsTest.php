<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Integration\Handler\Install;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Install\ComputeStaleBundledSkills;
use PHPUnit\Framework\TestCase;

final class ComputeStaleBundledSkillsTest extends TestCase
{
    public function testReturnsManifestPathsOfBundleSkillsNoLongerSelected(): void
    {
        $manifest = $this->manifest([
            '.claude/skills/alpha' => 'jardis/dev-skills',
            '.agents/skills/alpha' => 'jardis/dev-skills',
            '.claude/skills/beta' => 'jardis/dev-skills',
            '.agents/skills/beta' => 'jardis/dev-skills',
        ]);

        $stale = (new ComputeStaleBundledSkills())($manifest, [$this->skill('beta')]);

        self::assertSame(['.claude/skills/alpha', '.agents/skills/alpha'], $stale);
    }

    public function testIgnoresEntriesOfOtherSources(): void
    {
        $manifest = $this->manifest(['.claude/skills/vendor-skill' => 'jardisadapter/cache']);

        self::assertSame([], (new ComputeStaleBundledSkills())($manifest, []));
    }

    public function testFoldersMissingFromTheManifestAreNeverStale(): void
    {
        // A bundle name that is not in the manifest (user folder) is not derived from any list.
        $manifest = $this->manifest(['.claude/skills/alpha' => 'jardis/dev-skills']);

        self::assertSame([], (new ComputeStaleBundledSkills())($manifest, [$this->skill('alpha')]));
    }

    public function testWithoutManifestNothingIsStale(): void
    {
        self::assertSame([], (new ComputeStaleBundledSkills())(null, []));
    }

    /**
     * @param array<string, string> $sources path => source package
     */
    private function manifest(array $sources): Manifest
    {
        $entries = [];
        foreach ($sources as $path => $source) {
            $entries[$path] = ['source' => $source, 'sha256' => 'x'];
        }

        return new Manifest(Manifest::SCHEMA_VERSION, '1.4.0', $entries);
    }

    private function skill(string $name): SkillDescriptor
    {
        return new SkillDescriptor($name, '/irrelevant/' . $name, 'jardis/dev-skills');
    }
}
