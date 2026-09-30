<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class ResolveTargets
{
    /** The skill folder of Claude Code; the only one plugin versions up to 1.3.x wrote to. */
    public const CLAUDE_SKILLS_DIR = '.claude/skills';

    /** @var list<string> */
    public const TARGET_DIRS = [self::CLAUDE_SKILLS_DIR, '.agents/skills'];

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Resolves the skill target roots below the project root via `realpath`,
     * creating them if missing. Targets that resolve to the same real
     * directory (e.g. `.agents/skills` symlinked to `.claude/skills`) are
     * returned once, so nothing is written twice.
     *
     * @return list<string> absolute, real, unique skill root directories
     */
    public function __invoke(string $projectRoot): array
    {
        $resolved = [];

        foreach (self::TARGET_DIRS as $relative) {
            $path = $projectRoot . '/' . $relative;
            $this->filesystem->ensureDirectoryExists($path);

            $real = realpath($path);
            if ($real === false) {
                throw new InstallFailedException(sprintf('Could not resolve skill target "%s".', $path));
            }

            $resolved[$real] = $real;
        }

        return array_values($resolved);
    }
}
