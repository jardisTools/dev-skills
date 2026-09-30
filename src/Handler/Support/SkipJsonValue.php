<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

/**
 * Finds the end of one JSON value in a text that is known to be valid JSON
 * (the caller has decoded it before). No validation, only bracket and string tracking.
 */
final class SkipJsonValue
{
    private const WHITESPACE = " \t\r\n";

    /**
     * @param int $start offset of the first character of the value
     * @return int offset right behind the value
     */
    public function __invoke(string $json, int $start): int
    {
        $length = strlen($json);
        $char = $json[$start] ?? '';

        if ($char === '"') {
            return $this->skipString($json, $start);
        }

        if ($char === '{' || $char === '[') {
            $depth = 0;
            for ($pos = $start; $pos < $length; $pos++) {
                $current = $json[$pos];
                if ($current === '"') {
                    $pos = $this->skipString($json, $pos) - 1;
                } elseif ($current === '{' || $current === '[') {
                    $depth++;
                } elseif ($current === '}' || $current === ']') {
                    $depth--;
                    if ($depth === 0) {
                        return $pos + 1;
                    }
                }
            }

            return $length;
        }

        $pos = $start;
        while ($pos < $length && strpos(self::WHITESPACE . ',]}', $json[$pos]) === false) {
            $pos++;
        }

        return $pos;
    }

    private function skipString(string $json, int $start): int
    {
        $length = strlen($json);
        for ($pos = $start + 1; $pos < $length; $pos++) {
            if ($json[$pos] === '\\') {
                $pos++;
            } elseif ($json[$pos] === '"') {
                return $pos + 1;
            }
        }

        return $length;
    }
}
