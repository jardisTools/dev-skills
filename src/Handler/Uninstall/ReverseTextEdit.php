<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

/**
 * Takes back a recorded text change in a JSON text: `after` (which must be there exactly once)
 * is replaced by `before`. If the user has put another member right behind an inserted one,
 * the comma that joined them goes along. Returns null when the change cannot be found
 * or taking it back would leave invalid JSON; the caller then leaves the file alone.
 */
final class ReverseTextEdit
{
    public function __invoke(string $json, string $before, string $after): ?string
    {
        if ($after === '' || substr_count($json, $after) !== 1) {
            return null;
        }

        $position = (int) strpos($json, $after);
        $end = $position + strlen($after);
        $candidates = [substr_replace($json, $before, $position, strlen($after))];
        if (($json[$end] ?? '') === ',') {
            $candidates[] = substr_replace($json, $before, $position, strlen($after) + 1);
        }

        foreach ($candidates as $candidate) {
            if ($this->isValidJson($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isValidJson(string $json): bool
    {
        try {
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return true;
    }
}
