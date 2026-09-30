<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

use Closure;

/**
 * Lists the elements of a JSON array with the byte span of each one, in a text
 * that is known to be valid JSON.
 */
final class ScanJsonArray
{
    private const WHITESPACE = " \t\r\n";

    /**
     * @param Closure(string, int): int $skipValue
     */
    public function __construct(private readonly Closure $skipValue)
    {
    }

    /**
     * @param int $open offset of the opening bracket
     * @return list<array{start: int, end: int}> `end` is the offset right behind the element
     */
    public function __invoke(string $json, int $open): array
    {
        $elements = [];
        $pos = $open + 1;

        while (true) {
            $pos += strspn($json, self::WHITESPACE . ',', $pos);
            if (($json[$pos] ?? ']') === ']') {
                return $elements;
            }

            $end = ($this->skipValue)($json, $pos);
            if ($end <= $pos) {
                return $elements;
            }
            $elements[] = ['start' => $pos, 'end' => $end];
            $pos = $end;
        }
    }
}
