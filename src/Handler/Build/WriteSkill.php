<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

/**
 * Writes the rendered SKILL.md content to the given target path.
 * Creates any missing parent directories. Throws RuntimeException on
 * directory-creation or file-write failure.
 */
final class WriteSkill
{
    public function __invoke(string $targetPath, string $content): void
    {
        $dir = dirname($targetPath);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(
                sprintf('Cannot create target directory: %s', $dir),
            );
        }

        $result = file_put_contents($targetPath, $content, LOCK_EX);

        if ($result === false) {
            throw new \RuntimeException(
                sprintf('Cannot write SKILL.md to: %s', $targetPath),
            );
        }
    }
}
