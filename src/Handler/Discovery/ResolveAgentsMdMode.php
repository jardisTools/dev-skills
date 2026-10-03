<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\AgentsMdMode;

/**
 * Resolves the `agents-md` mode. An explicit valid value always wins. Without one, the name of the root
 * package decides: a package whose vendor part begins with `jardis` (jardis/, jardiscore/, jardissupport/,
 * jardisadapter/, jardistools/) ships its AGENTS.md as a deliverable and gets `none`; every other project
 * gets `aggregate`. An invalid value is reported and treated like an absent key.
 */
final class ResolveAgentsMdMode
{
    /**
     * @return array{AgentsMdMode, ?string} the mode and a warning about an invalid raw value
     */
    public function __invoke(bool $keyPresent, mixed $raw, string $rootPackageName): array
    {
        $default = $this->defaultFor($rootPackageName);
        if (!$keyPresent) {
            return [$default, null];
        }

        $mode = is_string($raw) ? AgentsMdMode::tryFrom($raw) : null;
        if ($mode !== null) {
            return [$mode, null];
        }

        return [$default, sprintf(
            'agents-md must be "aggregate" or "none"; got %s. Treated as agents-md=%s.',
            is_string($raw) ? '"' . $raw . '"' : get_debug_type($raw),
            $default->value,
        )];
    }

    private function defaultFor(string $rootPackageName): AgentsMdMode
    {
        return str_starts_with($rootPackageName, 'jardis') && str_contains($rootPackageName, '/')
            ? AgentsMdMode::None
            : AgentsMdMode::Aggregate;
    }
}
