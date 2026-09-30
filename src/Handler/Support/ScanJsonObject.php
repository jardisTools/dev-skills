<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

use Closure;

/**
 * Lists the members of a JSON object with the byte span of each value, in a text
 * that is known to be valid JSON. The spans let a caller edit the text without re-encoding it.
 */
final class ScanJsonObject
{
    private const WHITESPACE = " \t\r\n";

    /**
     * @param Closure(string, int): int $skipValue
     */
    public function __construct(private readonly Closure $skipValue)
    {
    }

    /**
     * @param int $open offset of the opening brace
     * @return list<array{key: string, start: int, end: int}> `end` is the offset right behind the value
     */
    public function __invoke(string $json, int $open): array
    {
        $members = [];
        $pos = $open + 1;

        while (true) {
            $pos += strspn($json, self::WHITESPACE . ',', $pos);
            if (($json[$pos] ?? '}') === '}') {
                return $members;
            }

            $keyEnd = ($this->skipValue)($json, $pos);
            $key = json_decode(substr($json, $pos, $keyEnd - $pos), true);
            $pos = $keyEnd + strspn($json, self::WHITESPACE . ':', $keyEnd);
            $end = ($this->skipValue)($json, $pos);
            if ($end <= $pos) {
                return $members;
            }

            $members[] = ['key' => is_string($key) ? $key : '', 'start' => $pos, 'end' => $end];
            $pos = $end;
        }
    }
}
