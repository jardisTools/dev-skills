<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Composer\Util\Filesystem;

final class RemoveStaleBundledSkills
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Removes bundled-skill directories from .claude/skills/ that are no longer
     * selected by the plugin config. The plugin owns these skill names, so
     * user-local modifications are overwritten.
     *
     * @param list<string> $skillNames names of bundled skills to remove if present
     * @return list<string> names that were actually removed from disk
     */
    public function __invoke(array $skillNames, string $projectRoot): array
    {
        $removed = [];
        foreach ($skillNames as $name) {
            $path = $projectRoot . '/.claude/skills/' . $name;
            if (!is_dir($path)) {
                continue;
            }
            $this->filesystem->removeDirectory($path);
            $removed[] = $name;
        }

        return $removed;
    }
}
