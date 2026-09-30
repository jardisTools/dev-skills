<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Support;

/**
 * The line ending a foreign file uses: CRLF as soon as it contains one, else LF.
 * Text the plugin adds to such a file must follow it.
 */
final class DetectLineEnding
{
    public function __invoke(string $content): string
    {
        return str_contains($content, "\r\n") ? "\r\n" : "\n";
    }
}
