<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\TextEdit;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Plans the one text change that makes `context.fileName` of a Gemini settings
 * text contain `AGENTS.md`, leaving every other character as it is:
 *
 * - no `context`: the whole member is inserted (`AGENTS.md` and `GEMINI.md`);
 * - `context` without `fileName`: the member is inserted into it (same two names);
 * - `fileName` is a string: it becomes a list with the string kept and `AGENTS.md` added;
 * - `fileName` is a list without `AGENTS.md`: the name is appended.
 *
 * Returns null when `AGENTS.md` is already there. A text that is not valid JSON, not an
 * object, or has a `context`/`fileName` of another shape cannot be edited character for
 * character and throws; so does a plan that would not produce a text with the entry.
 */
final class PlanGeminiContextEdit
{
    private const ENTRY = 'AGENTS.md';
    private const DEFAULT_NAMES = '["AGENTS.md", "GEMINI.md"]';

    /**
     * @param Closure(string): string                          $detectLineEnding
     * @param Closure(string, int): list<array{key: string, start: int, end: int}> $scanObject
     * @param Closure(string, int): list<array{start: int, end: int}>              $scanArray
     * @param Closure(string, int, int, string, string): TextEdit                  $buildMemberInsertion
     */
    public function __construct(
        private readonly Closure $detectLineEnding,
        private readonly Closure $scanObject,
        private readonly Closure $scanArray,
        private readonly Closure $buildMemberInsertion,
    ) {
    }

    public function __invoke(string $json): ?TextEdit
    {
        $edit = $this->plan($json);
        if ($edit === null) {
            return null;
        }

        $after = substr_replace($json, $edit->replacement, $edit->offset, $edit->length);
        $decoded = json_decode($after, true);
        $fileName = is_array($decoded) && is_array($decoded['context'] ?? null)
            ? ($decoded['context']['fileName'] ?? null)
            : null;
        $names = is_string($fileName) ? [$fileName] : $fileName;
        if (!is_array($names) || !in_array(self::ENTRY, $names, true)) {
            throw new InstallFailedException('The Gemini settings cannot be extended character for character.');
        }

        return $edit;
    }

    private function plan(string $json): ?TextEdit
    {
        try {
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InstallFailedException('The Gemini settings are not valid JSON.');
        }

        $open = strspn($json, " \t\r\n");
        if (($json[$open] ?? '') !== '{') {
            throw new InstallFailedException('The Gemini settings are not a JSON object.');
        }

        $eol = ($this->detectLineEnding)($json);
        $members = ($this->scanObject)($json, $open);
        $context = $this->lastMember($members, 'context');
        if ($context === null) {
            return ($this->buildMemberInsertion)(
                $json,
                $open,
                count($members),
                '"context": {"fileName": ' . self::DEFAULT_NAMES . '}',
                $eol,
            );
        }
        if ($json[$context['start']] !== '{') {
            throw new InstallFailedException('"context" in the Gemini settings is not an object.');
        }

        $contextMembers = ($this->scanObject)($json, $context['start']);
        $fileName = $this->lastMember($contextMembers, 'fileName');
        if ($fileName === null) {
            return ($this->buildMemberInsertion)(
                $json,
                $context['start'],
                count($contextMembers),
                '"fileName": ' . self::DEFAULT_NAMES,
                $eol,
            );
        }

        return $this->extendFileName($json, $fileName['start'], $fileName['end']);
    }

    private function extendFileName(string $json, int $start, int $end): ?TextEdit
    {
        $literal = substr($json, $start, $end - $start);
        $value = json_decode($literal, true);

        if (is_string($value)) {
            return $value === self::ENTRY
                ? null
                : new TextEdit($start, $end - $start, '[' . $literal . ', "AGENTS.md"]');
        }

        if (!is_array($value) || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            throw new InstallFailedException(
                '"context.fileName" in the Gemini settings is neither a string nor a list of strings.',
            );
        }
        if (in_array(self::ENTRY, $value, true)) {
            return null;
        }

        $elements = ($this->scanArray)($json, $start);
        if ($elements === []) {
            return new TextEdit($start + 1, 0, '"AGENTS.md"');
        }

        return new TextEdit($elements[count($elements) - 1]['end'], 0, ', "AGENTS.md"');
    }

    /**
     * @param list<array{key: string, start: int, end: int}> $members
     * @return array{key: string, start: int, end: int}|null the last one wins, as in a JSON decoder
     */
    private function lastMember(array $members, string $key): ?array
    {
        $found = null;
        foreach ($members as $member) {
            if ($member['key'] === $key) {
                $found = $member;
            }
        }

        return $found;
    }
}
