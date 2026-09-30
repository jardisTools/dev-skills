<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Record of the skill folders this plugin manages. Pure data: `entries` maps
 * a project-relative path to its origin and content checksum.
 */
final class Manifest
{
    public const FILE = '.claude/skills/.jardis-managed.json';
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, array{source: string, sha256: string}> $entries
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $pluginVersion,
        public readonly array $entries = [],
    ) {
    }
}
