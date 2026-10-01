<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Data\StagedSkill;

/**
 * Copies every selected skill into a staging directory beside each target
 * (`<skillsRoot>/.jardis-staging-<name>`). Nothing at the final targets is
 * touched; when any copy fails, every staging directory of this run is removed
 * again and the failure is rethrown.
 */
final class StageSkills
{
    public const STAGING_PREFIX = '.jardis-staging-';

    /**
     * @param Closure(SkillDescriptor, string): void $copySkill
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Closure $copySkill,
    ) {
    }

    /**
     * @param list<SkillDescriptor> $skills
     * @param list<string>          $targets absolute, real skill root directories
     * @return list<StagedSkill>
     */
    public function __invoke(array $skills, array $targets, string $projectRoot): array
    {
        $realRoot = (string) realpath($projectRoot);
        $staged = [];
        $created = [];

        try {
            foreach ($targets as $target) {
                foreach ($skills as $skill) {
                    $stagingDir = $target . '/' . self::STAGING_PREFIX . $skill->name;
                    $this->filesystem->remove($stagingDir);
                    $created[] = $stagingDir;
                    ($this->copySkill)($skill, $stagingDir);

                    $targetDir = $target . '/' . $skill->name;
                    $staged[] = new StagedSkill(
                        $skill,
                        $targetDir,
                        $stagingDir,
                        $this->manifestKey($targetDir, $realRoot),
                    );
                }
            }
        } catch (\Throwable $failure) {
            foreach ($created as $stagingDir) {
                $this->filesystem->remove($stagingDir);
            }

            throw $failure;
        }

        return $staged;
    }

    private function manifestKey(string $targetDir, string $realRoot): string
    {
        $prefix = $realRoot . '/';

        return str_starts_with($targetDir, $prefix) ? substr($targetDir, strlen($prefix)) : $targetDir;
    }
}
