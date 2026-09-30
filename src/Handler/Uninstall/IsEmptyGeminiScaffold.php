<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

/**
 * Tells whether a Gemini settings text holds nothing but an empty scaffold: `{}` or an
 * object whose only member is an empty `context`.
 */
final class IsEmptyGeminiScaffold
{
    public function __invoke(string $json): bool
    {
        $data = json_decode($json, true);

        return $data === [] || $data === ['context' => []];
    }
}
