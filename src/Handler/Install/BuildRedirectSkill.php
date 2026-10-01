<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Writes the redirect skill for a renamed bundle skill into `$destination`:
 * folder and `name` carry the old name, the description is the fixed line
 * DESCRIPTION, the body is one sentence. The new name comes from
 * RenamedSkills::MAPPING; a redirect has no source folder, so the descriptor's
 * `sourceDir` is not read.
 */
final class BuildRedirectSkill
{
    /** Fixed description of every redirect skill; `%1$s` is the new name. */
    public const DESCRIPTION = 'Renamed to %1$s. Load %1$s instead.';

    private const TEMPLATE = <<<'MD'
---
name: %1$s
description: %2$s
zone: crosscut
persona: C
prerequisites: []
next: []
---

## Renamed

This skill was renamed to `%3$s`; load `%3$s` instead.

MD;

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    public function __invoke(SkillDescriptor $redirect, string $destination): void
    {
        $newName = RenamedSkills::MAPPING[$redirect->name] ?? null;
        if ($newName === null) {
            throw new InstallFailedException(sprintf('"%s" is not a renamed bundle skill.', $redirect->name));
        }

        $this->filesystem->ensureDirectoryExists($destination);

        $content = sprintf(self::TEMPLATE, $redirect->name, sprintf(self::DESCRIPTION, $newName), $newName);
        if (@file_put_contents($destination . '/SKILL.md', $content) === false) {
            throw new InstallFailedException(sprintf('Could not write the redirect skill into "%s".', $destination));
        }
    }
}
