<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * One file of a work folder `docs/vorhaben/<name>/`: the progress file, the plan or a stage plan.
 * Lines are the stored lines without the last empty one, in file order; line numbers elsewhere are 1-based.
 */
final class VorhabenFile
{
    public const KIND_PROGRESS   = 'progress';
    public const KIND_PLAN       = 'plan';
    public const KIND_STAGE_PLAN = 'stage-plan';

    /**
     * @param string       $file  path relative to the project root
     * @param string       $kind  one of the KIND_* constants
     * @param int          $bytes size of the stored file
     * @param list<string> $lines
     */
    public function __construct(
        public readonly string $file,
        public readonly string $kind,
        public readonly int $bytes,
        public readonly array $lines,
    ) {
    }
}
