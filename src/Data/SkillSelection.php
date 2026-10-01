<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class SkillSelection
{
    /**
     * @param list<SkillDescriptor> $skills   skills that survive collision resolution
     * @param list<string>          $warnings human-readable collision warnings
     */
    public function __construct(
        public array $skills,
        public array $warnings,
    ) {
    }
}
