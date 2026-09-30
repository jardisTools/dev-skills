<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use Closure;

/**
 * A TOML multi-line string. The text goes into a `'''` literal string as it is; when it holds `'''`
 * or a control character a literal cannot carry, the evasion form `"""` is used, each line escaped as in a
 * basic string.
 */
final class EncodeTomlMultiline
{
    /**
     * @param Closure(string): string $encodeBasicString
     */
    public function __construct(private readonly Closure $encodeBasicString)
    {
    }

    public function __invoke(string $text): string
    {
        if (!str_contains($text, "'''") && preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text) !== 1) {
            return "'''\n" . $text . "\n'''";
        }

        $lines = array_map(
            fn (string $line): string => substr(($this->encodeBasicString)($line), 1, -1),
            explode("\n", $text),
        );

        return "\"\"\"\n" . implode("\n", $lines) . "\n\"\"\"";
    }
}
