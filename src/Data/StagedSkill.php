<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * A skill copied into a staging directory next to its final target, waiting to
 * be swapped in. `manifestKey` is the project-relative path of the target.
 */
final readonly class StagedSkill
{
    public function __construct(
        public SkillDescriptor $skill,
        public string $targetDir,
        public string $stagingDir,
        public string $manifestKey,
    ) {
    }
}
