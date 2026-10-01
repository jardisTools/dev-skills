<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Data\VorhabenFile;

/**
 * Checks the head of every progress file: the section `## Kopf` is the first heading after the title and
 * carries four lines in this order, `- **Phase:**`, `- **Stage:**`, `- **Next step:**`, `- **Open decisions:**`.
 * The phase is one of eight values, the stage is `E<n>/<total>` from phase `stage` on and `—` before it, the open
 * decisions are `—` or `STOPP: <ISO date> · <question>`. The file has at most 60 lines. Lines after the four are
 * free. Reports only; it never rewrites a file.
 */
final class CheckProgressHead
{
    public const MAX_LINES = 60;

    /** The phases in workflow order; from `stage` on (index 5) the stage line is numbered. */
    public const PHASES = ['concept', 'prd', 'prd-review', 'plan', 'plan-review', 'stage', 'acceptance', 'close'];

    private const FIRST_NUMBERED_PHASE = 5;
    private const KEYS = ['Phase', 'Stage', 'Next step', 'Open decisions'];
    private const NONE = '—';

    /**
     * @param list<VorhabenFile> $files every file found in the work folders; only progress files are checked
     * @return list<PoolViolation>
     */
    public function __invoke(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            if ($file->kind === VorhabenFile::KIND_PROGRESS) {
                array_push($violations, ...$this->checkFile($file));
            }
        }

        return $violations;
    }

    /**
     * @return list<PoolViolation>
     */
    private function checkFile(VorhabenFile $file): array
    {
        $violations = [];
        if (count($file->lines) > self::MAX_LINES) {
            $violations[] = new PoolViolation(
                $file->file,
                self::MAX_LINES + 1,
                PoolViolation::RULE_PROGRESS_LINES,
                sprintf('file has %d lines, the cap is %d', count($file->lines), self::MAX_LINES),
            );
        }

        $headings = $this->headings($file->lines);
        $title    = $headings !== [] && $headings[0]['level'] === 1 ? 1 : 0;
        $kopf     = null;
        foreach ($headings as $index => $heading) {
            if ($heading['level'] === 2 && $heading['title'] === 'Kopf') {
                $kopf = $index;
                break;
            }
        }

        if ($kopf === null) {
            $violations[] = new PoolViolation(
                $file->file,
                1,
                PoolViolation::RULE_HEAD_MISSING,
                "section '## Kopf' is missing",
            );

            return $violations;
        }
        if ($kopf !== $title) {
            $violations[] = new PoolViolation(
                $file->file,
                $headings[$title]['line'],
                PoolViolation::RULE_HEAD_POSITION,
                "section '## Kopf' must be the first heading after the title",
            );
        }

        $end = $headings[$kopf + 1]['line'] ?? count($file->lines) + 1;
        array_push($violations, ...$this->checkHead($file, $headings[$kopf]['line'], $end));

        return $violations;
    }

    /**
     * @return list<PoolViolation>
     */
    private function checkHead(VorhabenFile $file, int $kopfLine, int $end): array
    {
        $violations = [];
        $values     = [];
        $position   = 0;

        for ($no = $kopfLine + 1; $no < $end && $position < count(self::KEYS); $no++) {
            $line = $file->lines[$no - 1];
            if (trim($line) === '') {
                continue;
            }
            $expected = self::KEYS[$position];
            if (preg_match('/^- \*\*([^*]+?):\*\*(.*)$/u', $line, $match) !== 1) {
                $violations[] = new PoolViolation(
                    $file->file,
                    $no,
                    PoolViolation::RULE_HEAD_KEY,
                    sprintf("line %d of the head must be '- **%s:** <value>'", $position + 1, $expected),
                );
                break;
            }
            if ($match[1] !== $expected) {
                $violations[] = new PoolViolation(
                    $file->file,
                    $no,
                    PoolViolation::RULE_HEAD_KEY,
                    sprintf(
                        "expected key '%s' at line %d of the head, found '%s'",
                        $expected,
                        $position + 1,
                        $match[1],
                    ),
                );
            }
            $values[$match[1]] = ['line' => $no, 'value' => trim($match[2])];
            $position++;
        }

        if ($position < count(self::KEYS)) {
            $missing = array_slice(self::KEYS, $position);
            if ($violations === []) {
                $violations[] = new PoolViolation(
                    $file->file,
                    $kopfLine,
                    PoolViolation::RULE_HEAD_KEY,
                    sprintf('the head lacks the line(s) %s', implode(', ', $missing)),
                );
            }
        }

        return [...$violations, ...$this->checkValues($file, $values)];
    }

    /**
     * @param array<string, array{line: int, value: string}> $values
     * @return list<PoolViolation>
     */
    private function checkValues(VorhabenFile $file, array $values): array
    {
        $violations = [];

        $phase = null;
        if (isset($values['Phase'])) {
            $phase = array_search($values['Phase']['value'], self::PHASES, true);
            if ($phase === false) {
                $phase        = null;
                $violations[] = new PoolViolation(
                    $file->file,
                    $values['Phase']['line'],
                    PoolViolation::RULE_HEAD_PHASE,
                    sprintf("phase '%s' is not one of %s", $values['Phase']['value'], implode(', ', self::PHASES)),
                );
            }
        }

        if (isset($values['Stage'])) {
            $stage    = $values['Stage']['value'];
            $numbered = preg_match('#^E[1-9][0-9]*/[1-9][0-9]*$#', $stage) === 1;
            $problem  = match (true) {
                $phase === null => $numbered || $stage === self::NONE ? null : "stage must be 'E<n>/<total>' or '—'",
                $phase >= self::FIRST_NUMBERED_PHASE
                    => $numbered ? null : "from phase 'stage' on the stage is 'E<n>/<total>'",
                default => $stage === self::NONE ? null : "before phase 'stage' the stage is '—'",
            };
            if ($problem !== null) {
                $violations[] = new PoolViolation(
                    $file->file,
                    $values['Stage']['line'],
                    PoolViolation::RULE_HEAD_STAGE,
                    $problem,
                );
            }
        }

        if (isset($values['Next step']) && $values['Next step']['value'] === '') {
            $violations[] = new PoolViolation(
                $file->file,
                $values['Next step']['line'],
                PoolViolation::RULE_HEAD_KEY,
                "'Next step' has no value",
            );
        }

        if (isset($values['Open decisions']) && !$this->isOpenDecisions($values['Open decisions']['value'])) {
            $violations[] = new PoolViolation(
                $file->file,
                $values['Open decisions']['line'],
                PoolViolation::RULE_HEAD_DECISIONS,
                "open decisions must be '—' or 'STOPP: <YYYY-MM-DD> · <question>'",
            );
        }

        return $violations;
    }

    private function isOpenDecisions(string $value): bool
    {
        if ($value === self::NONE) {
            return true;
        }
        if (preg_match('/^STOPP: (\d{4})-(\d{2})-(\d{2}) · \S/u', $value, $match) !== 1) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    /**
     * @param list<string> $lines
     * @return list<array{line: int, level: int, title: string}> every heading outside fenced code blocks
     */
    private function headings(array $lines): array
    {
        $headings = [];
        $fenced   = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^ {0,3}(```|~~~)/', $line) === 1) {
                $fenced = !$fenced;
                continue;
            }
            if (!$fenced && preg_match('/^(#{1,6})[ \t]+(\S.*?)[ \t]*$/u', $line, $match) === 1) {
                $headings[] = ['line' => $index + 1, 'level' => strlen($match[1]), 'title' => $match[2]];
            }
        }

        return $headings;
    }
}
