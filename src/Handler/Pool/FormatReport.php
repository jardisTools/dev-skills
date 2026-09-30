<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolCheckResult;

/**
 * Turns a pool check result into output lines: one `file:line rule message` line per violation, then one
 * summary line.
 */
final class FormatReport
{
    /**
     * @return list<string>
     */
    public function __invoke(PoolCheckResult $result): array
    {
        $lines = [];
        $files = [];
        foreach ($result->violations as $violation) {
            $lines[] = sprintf(
                '%s:%d %s %s',
                $violation->file,
                $violation->line,
                $violation->rule,
                $violation->message,
            );
            $files[$violation->file] = true;
        }

        $lines[] = $result->violations === []
            ? sprintf('Pool clean (%d page(s) checked).', $result->pages)
            : sprintf(
                '%d violation(s) in %d file(s) (%d page(s) checked).',
                count($result->violations),
                count($files),
                $result->pages,
            );

        return $lines;
    }
}
