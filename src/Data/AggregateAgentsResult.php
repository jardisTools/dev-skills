<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class AggregateAgentsResult
{
    public function __construct(
        public int $aggregatedCount,
        public ?string $backupPath,
        public bool $healedDuplicateBlock = false,
        public ?string $sizeWarning = null,
        public bool $agentsMdCreated = false,
        public ?string $skippedWarning = null,
    ) {
    }
}
