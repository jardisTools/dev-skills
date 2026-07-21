<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class AgentsDescriptor
{
    public function __construct(
        public string $sourcePackage,
        public string $content,
    ) {
    }
}
