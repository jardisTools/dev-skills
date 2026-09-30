<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

/**
 * A TOML basic string (double-quoted, single line): backslash, double quote and every control
 * character are escaped, so any text comes out as valid TOML, including text with `'''`.
 */
final class EncodeTomlBasicString
{
    private const SHORT_ESCAPES = [8 => '\b', 9 => '\t', 10 => '\n', 12 => '\f', 13 => '\r'];

    public function __invoke(string $text): string
    {
        $escaped = preg_replace_callback(
            '/[\x00-\x1F"\\\\\x7F]/',
            static function (array $match): string {
                $char = $match[0];
                if ($char === '"' || $char === '\\') {
                    return '\\' . $char;
                }

                return self::SHORT_ESCAPES[ord($char)] ?? sprintf('\u%04X', ord($char));
            },
            $text,
        );

        return '"' . ($escaped ?? '') . '"';
    }
}
