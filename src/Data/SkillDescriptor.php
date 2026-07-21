<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class SkillDescriptor
{
    public function __construct(
        public string $name,
        public string $sourceDir,
        public string $sourcePackage,
    ) {
    }
}
