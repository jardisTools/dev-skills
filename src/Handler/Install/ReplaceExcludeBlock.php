<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Puts the plugin's marked block into the text of a Git exclude file, replaces it, or takes it out.
 * Everything outside the block stays byte for byte; a new block follows the line ending the file
 * already uses (CRLF as soon as it contains one, else LF). A file with only one marker, more
 * than one block, or the markers in the wrong order is corrupt: InstallFailedException.
 */
final class ReplaceExcludeBlock
{
    public const BEGIN = '# BEGIN jardis/dev-skills (managed block, do not edit by hand)';
    public const END = '# END jardis/dev-skills';

    /**
     * @param Closure(string): string $detectLineEnding
     */
    public function __construct(private readonly Closure $detectLineEnding)
    {
    }

    /**
     * @param list<string>|null $lines the lines of the block; null takes the block out
     */
    public function __invoke(string $content, ?array $lines): string
    {
        $begins = $this->markerLines($content, self::BEGIN);
        $ends = $this->markerLines($content, self::END);
        if (count($begins) !== count($ends) || count($begins) > 1) {
            throw new InstallFailedException(sprintf(
                'the exclude file has corrupt managed-block markers (found %d BEGIN and %d END).',
                count($begins),
                count($ends),
            ));
        }

        $range = null;
        if ($begins !== []) {
            [$start] = $begins[0];
            [$endStart, $endLength] = $ends[0];
            if ($endStart < $start) {
                throw new InstallFailedException('the exclude file has a managed-block END before its BEGIN.');
            }
            $range = [$start, $endStart + $endLength - $start];
        }

        if ($lines === null) {
            return $range === null ? $content : substr_replace($content, '', $range[0], $range[1]);
        }

        $eol = ($this->detectLineEnding)($content);
        $block = implode($eol, [self::BEGIN, ...$lines, self::END]) . $eol;

        if ($range !== null) {
            return substr_replace($content, $block, $range[0], $range[1]);
        }

        $separator = $content !== '' && !str_ends_with($content, "\n") ? $eol : '';

        return $content . $separator . $block;
    }

    /**
     * @return list<array{int, int}> offset and length (including the line end) of each line that is exactly the marker
     */
    private function markerLines(string $content, string $marker): array
    {
        preg_match_all(
            '/^' . preg_quote($marker, '/') . '(?:\r?\n|\z)/m',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        return array_map(
            static fn (array $match): array => [$match[1], strlen($match[0])],
            $matches[0],
        );
    }
}
