<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

/**
 * Test-only parser for the subset of TOML the reviewer shells use: comments, blank lines and
 * `key = "string"` pairs, the value being a basic string (`"..."`, with escapes), a literal string
 * (`'...'`), or one of the two multi-line forms (`"""..."""` with escapes, `'''...'''` literal;
 * a newline right after the opening quotes is dropped). Everything else throws: this parser may only
 * accept what it knows. It is deliberately independent of the plugin's writer; the Codex
 * documentation example in tests/Fixture/Reviewers keeps it honest.
 */
final class MiniToml
{
    private const SHORT_ESCAPES = [
        'b' => "\x08", 't' => "\t", 'n' => "\n", 'f' => "\x0C", 'r' => "\r", '"' => '"', '\\' => '\\',
    ];

    /**
     * @return array<string, string>
     */
    public static function parse(string $toml): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $toml));
        $values = [];

        for ($index = 0; $index < count($lines); $index++) {
            $line = trim($lines[$index]);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\s*([A-Za-z0-9_-]+)\s*=\s*(.*)$/', $lines[$index], $match) !== 1) {
                throw new \InvalidArgumentException(sprintf('Line %d is not a key/value pair.', $index + 1));
            }
            $key = $match[1];
            if (array_key_exists($key, $values)) {
                throw new \InvalidArgumentException(sprintf('Duplicate key "%s".', $key));
            }

            $values[$key] = self::parseValue($match[2], $lines, $index);
        }

        return $values;
    }

    /**
     * @param list<string> $lines
     */
    private static function parseValue(string $rest, array $lines, int &$index): string
    {
        foreach (['"""', "'''"] as $delimiter) {
            if (str_starts_with($rest, $delimiter)) {
                return self::parseMultiline(substr($rest, 3), $delimiter, $lines, $index);
            }
        }
        if ($rest !== '' && ($rest[0] === '"' || $rest[0] === "'")) {
            return self::parseSingleLine($rest);
        }

        throw new \InvalidArgumentException(sprintf('Unsupported value on line %d.', $index + 1));
    }

    private static function parseSingleLine(string $rest): string
    {
        $quote = $rest[0];
        $body = '';
        $length = strlen($rest);
        for ($i = 1; $i < $length; $i++) {
            $char = $rest[$i];
            if ($char === $quote) {
                $tail = trim(substr($rest, $i + 1));
                if ($tail !== '' && $tail[0] !== '#') {
                    throw new \InvalidArgumentException('Unexpected text after a string value.');
                }

                return $quote === '"' ? self::unescape($body) : $body;
            }
            if ($char === '\\' && $quote === '"') {
                $body .= $char . ($rest[++$i] ?? '');
                continue;
            }
            $body .= $char;
        }

        throw new \InvalidArgumentException('Unterminated string.');
    }

    /**
     * @param list<string> $lines
     */
    private static function parseMultiline(string $first, string $delimiter, array $lines, int &$index): string
    {
        $text = $first;
        $startLine = $index + 1;
        while (true) {
            $position = self::closingPosition($text, $delimiter);
            if ($position !== null) {
                $tail = trim(substr($text, $position + 3));
                if ($tail !== '' && $tail[0] !== '#') {
                    throw new \InvalidArgumentException('Unexpected text after a multi-line string.');
                }
                $body = substr($text, 0, $position);
                break;
            }
            $index++;
            if ($index >= count($lines)) {
                throw new \InvalidArgumentException(sprintf('Unterminated multi-line string from line %d.', $startLine));
            }
            $text .= "\n" . $lines[$index];
        }

        if (str_starts_with($body, "\n")) {
            $body = substr($body, 1);
        }

        return $delimiter === '"""' ? self::unescape($body) : $body;
    }

    /**
     * Position of the closing delimiter: for `"""` the first one not escaped by a backslash.
     */
    private static function closingPosition(string $text, string $delimiter): ?int
    {
        $length = strlen($text);
        for ($i = 0; $i + 3 <= $length; $i++) {
            if ($delimiter === '"""' && $text[$i] === '\\') {
                $i++;
                continue;
            }
            if (substr($text, $i, 3) === $delimiter) {
                return $i;
            }
        }

        return null;
    }

    private static function unescape(string $body): string
    {
        $result = preg_replace_callback(
            '/\\\\(?:u([0-9A-Fa-f]{4})|U([0-9A-Fa-f]{8})|(.))/s',
            static function (array $match): string {
                if ($match[1] !== '') {
                    return mb_chr((int) hexdec($match[1]), 'UTF-8');
                }
                if ($match[2] !== '') {
                    return mb_chr((int) hexdec($match[2]), 'UTF-8');
                }
                $char = $match[3];
                if (!isset(self::SHORT_ESCAPES[$char])) {
                    throw new \InvalidArgumentException(sprintf('Unsupported escape "\\%s".', $char));
                }

                return self::SHORT_ESCAPES[$char];
            },
            $body,
        );

        return $result ?? throw new \InvalidArgumentException('Invalid escape sequence.');
    }
}
