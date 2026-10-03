<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Record of what this plugin manages. Pure data: `entries` maps a skill folder (project-relative path)
 * to its origin and content checksum; `selfSet` maps a foreign file the plugin changed itself
 * (project-relative path, e.g. CLAUDE.md) to what it set there. `profile` is the installation profile of the
 * last run; `null` for a manifest written before the profile existed.
 */
final class Manifest
{
    public const FILE = '.claude/skills/.jardis-managed.json';
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, array{source: string, sha256: string}> $entries
     * @param array<string, SelfSetEntry> $selfSet
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $pluginVersion,
        public readonly array $entries = [],
        public readonly array $selfSet = [],
        public readonly ?InstallProfile $profile = null,
    ) {
    }
}
