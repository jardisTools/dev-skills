<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\PoolViolation;
use JardisTools\DevSkills\Data\VorhabenFile;

/**
 * Checks the size caps of the plans: a stage plan `PLAN-E*.md` has at most 150 lines, in `PLAN.md` every stage
 * section `## E<n>` has at most 150 lines (heading included, trailing blank lines not counted), and both kinds of
 * file stay within 16384 bytes. Reports only; it never splits or trims a plan.
 */
final class CheckPlanBudget
{
    public const MAX_LINES = 150;
    public const MAX_BYTES = 16384;

    /**
     * @param list<VorhabenFile> $files every file found in the work folders; only plans are checked
     * @return list<PoolViolation>
     */
    public function __invoke(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            if ($file->kind === VorhabenFile::KIND_STAGE_PLAN) {
                array_push($violations, ...$this->checkStagePlan($file));
            } elseif ($file->kind === VorhabenFile::KIND_PLAN) {
                array_push($violations, ...$this->checkPlan($file));
            }
        }

        return $violations;
    }

    /**
     * @return list<PoolViolation>
     */
    private function checkStagePlan(VorhabenFile $file): array
    {
        $violations = $this->checkBytes($file);
        if (count($file->lines) > self::MAX_LINES) {
            $violations[] = new PoolViolation(
                $file->file,
                self::MAX_LINES + 1,
                PoolViolation::RULE_PLAN_LINES,
                sprintf('stage plan has %d lines, the cap is %d', count($file->lines), self::MAX_LINES),
            );
        }

        return $violations;
    }

    /**
     * @return list<PoolViolation>
     */
    private function checkPlan(VorhabenFile $file): array
    {
        $violations = $this->checkBytes($file);

        $fenced = false;
        $starts = [];
        foreach ($file->lines as $index => $line) {
            if (preg_match('/^ {0,3}(```|~~~)/', $line) === 1) {
                $fenced = !$fenced;
                continue;
            }
            if (!$fenced && preg_match('/^##[ \t]+(\S.*?)[ \t]*$/u', $line, $match) === 1) {
                $starts[] = ['line' => $index + 1, 'title' => $match[1]];
            }
        }

        foreach ($starts as $position => $start) {
            if (preg_match('/^E[0-9]+(\s|$)/', $start['title']) !== 1) {
                continue;
            }
            $last = ($starts[$position + 1]['line'] ?? count($file->lines) + 1) - 1;
            while ($last > $start['line'] && trim($file->lines[$last - 1]) === '') {
                $last--;
            }
            $length = $last - $start['line'] + 1;
            if ($length > self::MAX_LINES) {
                $violations[] = new PoolViolation(
                    $file->file,
                    $start['line'] + self::MAX_LINES,
                    PoolViolation::RULE_PLAN_SECTION,
                    sprintf("section '%s' has %d lines, the cap is %d", $start['title'], $length, self::MAX_LINES),
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<PoolViolation>
     */
    private function checkBytes(VorhabenFile $file): array
    {
        if ($file->bytes <= self::MAX_BYTES) {
            return [];
        }

        return [
            new PoolViolation(
                $file->file,
                1,
                PoolViolation::RULE_PLAN_BYTES,
                sprintf('plan has %d bytes, the cap is %d', $file->bytes, self::MAX_BYTES),
            ),
        ];
    }
}
