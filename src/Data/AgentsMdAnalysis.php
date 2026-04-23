<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class AgentsMdAnalysis
{
    public function __construct(
        public bool $fileExisted,
        public bool $hasManagedBlock,
        public string $preBlock,
        public string $postBlock,
    ) {
    }
}
