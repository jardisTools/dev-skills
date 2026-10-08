<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * The agent tools a project can ask reviewer shells for (`hosts`). The backing value is the name used in the
 * `extra."jardis/dev-skills"` config; the shell format of the tool comes from {@see ShellFormat}.
 */
enum Host: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case Cursor = 'cursor';
    case Copilot = 'copilot';
    case Gemini = 'gemini';

    public function shellFormat(): ShellFormat
    {
        return match ($this) {
            self::Claude => ShellFormat::Claude,
            self::Codex => ShellFormat::Codex,
            self::Cursor => ShellFormat::Cursor,
            self::Copilot => ShellFormat::Copilot,
            self::Gemini => ShellFormat::Gemini,
        };
    }

    /**
     * The hosts a project gets without a `hosts` key: only the tested tool.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::Claude];
    }
}
