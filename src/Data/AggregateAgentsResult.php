<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

final readonly class AggregateAgentsResult
{
    public function __construct(
        public int $aggregatedCount,
        public ?string $backupPath,
    ) {
    }
}
