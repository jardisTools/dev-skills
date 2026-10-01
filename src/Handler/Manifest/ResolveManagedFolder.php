<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

/**
 * The one rule that turns a manifest key into a file system path.
 *
 * A key is honoured only as a relative `.claude/skills/<name>` or `.agents/skills/<name>`
 * of the project whose real path is exactly that location. Anything else (absolute path,
 * `..` or `.` segment, nested path, foreign folder, symlink) is not a folder the plugin
 * manages: a tampered or damaged manifest must never lead to backing up, deleting or
 * carrying on anything outside the skill folders of the project.
 */
final class ResolveManagedFolder
{
    private const MANAGED_KEY_PATTERN = '#^\.(?:claude|agents)/skills/(?!\.\.?$)[^/\0\r\n]+$#D';

    /**
     * @param string $realRoot real path of the project root
     * @return string|null the managed folder, '' when it does not exist (nothing to do),
     *                     null when the key is not a safe skill folder of this project
     */
    public function __invoke(string $realRoot, string $key): ?string
    {
        if (preg_match(self::MANAGED_KEY_PATTERN, $key) !== 1) {
            return null;
        }

        $path = $realRoot . '/' . $key;
        if (!file_exists($path) && !is_link($path)) {
            return '';
        }

        return realpath($path) === $path && is_dir($path) ? $path : null;
    }
}
