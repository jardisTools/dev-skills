<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Validate;

/**
 * Checks the line length and the byte size of a skill's SKILL.md and of its
 * leaves `reference/*.md` (docs/SKILL-FORMAT.md §5):
 *   - no line longer than MAX_LINE_CHARS characters (UTF-8; lines end at CRLF, LF or CR)
 *   - SKILL.md at most MAX_INDEX_BYTES when the skill has at least one leaf,
 *     otherwise at most MAX_FILE_BYTES
 *   - every leaf at most MAX_FILE_BYTES
 *
 * A leaf is a `.md` file directly under `reference/` (the tree form requires
 * flat leaves). Subdirectories and files with another extension are not
 * leaves and are not checked; an empty `reference/` directory does not make
 * SKILL.md an index.
 *
 * A file over its byte cap is reported once and its lines are not scanned.
 * Returns human-readable violations naming the file relative to the skill
 * folder; an empty list means the skill is within all limits.
 */
final class CheckSkillSize
{
    /** Gemini CLI's read tool truncates each line at this many characters. */
    public const MAX_LINE_CHARS = 2000;

    /** SKILL.md cap when the skill splits its content into `reference/` leaves. */
    public const MAX_INDEX_BYTES = 12288;

    /** Cap of a SKILL.md without leaves and of every leaf. */
    public const MAX_FILE_BYTES = 30720;

    private const REFERENCE_DIR = 'reference';

    /**
     * @return list<string>
     */
    public function __invoke(string $skillMdPath): array
    {
        if (!is_file($skillMdPath)) {
            return [sprintf('file does not exist: %s', $skillMdPath)];
        }

        $leaves    = $this->collectLeaves(dirname($skillMdPath) . '/' . self::REFERENCE_DIR);
        $hasLeaves = $leaves !== [];

        $errors = $this->checkFile(
            $skillMdPath,
            basename($skillMdPath),
            $hasLeaves ? self::MAX_INDEX_BYTES : self::MAX_FILE_BYTES,
            $hasLeaves ? 'index cap' : 'cap',
        );

        foreach ($leaves as $leaf) {
            $label  = self::REFERENCE_DIR . '/' . basename($leaf);
            $errors = [...$errors, ...$this->checkFile($leaf, $label, self::MAX_FILE_BYTES, 'leaf cap')];
        }

        return $errors;
    }

    /**
     * Sorted paths of the `.md` files directly under the reference directory.
     * Listed without glob(), which would read metacharacters in the path as a pattern.
     *
     * @return list<string>
     */
    private function collectLeaves(string $referenceDir): array
    {
        if (!is_dir($referenceDir)) {
            return [];
        }

        $names = scandir($referenceDir);
        if ($names === false) {
            return [];
        }

        $leaves = [];
        foreach ($names as $name) {
            if ($name[0] !== '.' && str_ends_with($name, '.md') && is_file($referenceDir . '/' . $name)) {
                $leaves[] = $referenceDir . '/' . $name;
            }
        }
        sort($leaves);

        return $leaves;
    }

    /**
     * @return list<string>
     */
    private function checkFile(string $path, string $label, int $maxBytes, string $capName): array
    {
        $bytes = filesize($path);
        if ($bytes === false) {
            return [sprintf('cannot read file: %s', $label)];
        }

        if ($bytes > $maxBytes) {
            return [sprintf('%s: %d bytes exceeds the %s of %d', $label, $bytes, $capName, $maxBytes)];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return [sprintf('cannot read file: %s', $label)];
        }

        $errors = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $line) {
            $chars = $this->countChars($line);
            if ($chars > self::MAX_LINE_CHARS) {
                $errors[] = sprintf(
                    '%s:%d: line has %d characters, max %d',
                    $label,
                    $index + 1,
                    $chars,
                    self::MAX_LINE_CHARS,
                );
            }
        }

        return $errors;
    }

    /** UTF-8 characters of a line; invalid UTF-8 falls back to the byte count. */
    private function countChars(string $line): int
    {
        $bytes = strlen($line);
        if ($bytes <= self::MAX_LINE_CHARS) {
            return $bytes; // a line within the limit in bytes is within it in characters
        }

        $count = preg_match_all('/./su', $line);

        return $count === false ? $bytes : $count;
    }
}
