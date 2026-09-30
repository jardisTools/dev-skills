<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Data;

/**
 * The five tool formats a reviewer shell is written in. The backing value is the project-relative
 * path of the shell file, `%s` standing for the role; nothing else decides where a shell goes.
 */
enum ShellFormat: string
{
    case Claude = '.claude/agents/%s.md';
    case Codex = '.codex/agents/%s.toml';
    case Cursor = '.cursor/agents/%s.md';
    case Copilot = '.github/agents/%s.agent.md';
    case Gemini = '.gemini/agents/%s.md';

    /** What a role (and with it a shell file name) may look like. */
    public const ROLE_PATTERN = '[a-z0-9][a-z0-9-]{0,63}';

    public function pathFor(string $role): string
    {
        return sprintf($this->value, $role);
    }

    /**
     * The format a manifest key is a shell path of, or null when it is no shell path of the plugin.
     */
    public static function fromPath(string $path): ?self
    {
        foreach (self::cases() as $format) {
            $pattern = str_replace('%s', self::ROLE_PATTERN, preg_quote($format->value, '#'));
            if (preg_match('#^' . $pattern . '$#D', $path) === 1) {
                return $format;
            }
        }

        return null;
    }
}
