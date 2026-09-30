<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

final class BuildAgentsMdSizeWarning
{
    /** Codex `project_doc_max_bytes` default: bytes of AGENTS.md that are read. */
    public const LIMIT_BYTES = 32768;

    /**
     * Returns a warning when the AGENTS.md size in bytes exceeds the Codex
     * limit, null when it is within (size equal to the limit is still read
     * completely).
     */
    public function __invoke(int $bytes): ?string
    {
        if ($bytes <= self::LIMIT_BYTES) {
            return null;
        }

        return sprintf(
            'AGENTS.md is %d bytes, above the Codex limit of %d bytes (project_doc_max_bytes); '
            . 'Codex reads only the first %d bytes.',
            $bytes,
            self::LIMIT_BYTES,
            self::LIMIT_BYTES,
        );
    }
}
