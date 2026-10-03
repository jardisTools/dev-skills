<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * Whether the plugin writes its managed block (router plus the aggregated AGENTS.md of the vendor
 * packages) into the AGENTS.md of the project (`aggregate`), or keeps the project's AGENTS.md, CLAUDE.md
 * import and Gemini entry free of anything it manages (`none`).
 */
enum AgentsMdMode: string
{
    case Aggregate = 'aggregate';
    case None = 'none';
}
