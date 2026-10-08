<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\Host;

/**
 * Resolves the `hosts` list. An absent key means the default (`claude`). A valid list of host names wins
 * (duplicates collapse, the order of the enum applies); a value that is no list, or a list with an unknown
 * or non-string entry, is reported and treated like an absent key, so a typo never writes shells for
 * tools nobody asked for.
 */
final class ResolveHosts
{
    /**
     * @return array{list<Host>, ?string} the hosts and a warning about an invalid raw value
     */
    public function __invoke(bool $keyPresent, mixed $raw): array
    {
        if (!$keyPresent) {
            return [Host::defaults(), null];
        }

        if (is_array($raw) && array_is_list($raw)) {
            $chosen = [];
            foreach ($raw as $item) {
                $host = is_string($item) ? Host::tryFrom($item) : null;
                if ($host === null) {
                    return [Host::defaults(), $this->warning(
                        sprintf('entry %s is unknown', is_string($item) ? '"' . $item . '"' : get_debug_type($item)),
                    )];
                }
                $chosen[$host->value] = $host;
            }

            return [array_values(array_filter(
                Host::cases(),
                static fn (Host $host): bool => isset($chosen[$host->value]),
            )), null];
        }

        return [Host::defaults(), $this->warning(sprintf('got %s instead of a list', get_debug_type($raw)))];
    }

    private function warning(string $reason): string
    {
        return sprintf(
            'hosts must be a list of "claude", "codex", "cursor", "copilot", "gemini"; %s.'
            . ' Treated as hosts=["claude"].',
            $reason,
        );
    }
}
