<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class CopySkill
{
    /**
     * @param Closure(string): string $handleConflict
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Closure $handleConflict,
    ) {
    }

    /**
     * Copies a skill directory into <projectRoot>/.claude/skills/<name>/.
     *
     * Returns the backup path if a pre-existing directory was moved aside,
     * otherwise null.
     */
    public function __invoke(SkillDescriptor $skill, string $projectRoot): ?string
    {
        $target = $projectRoot . '/.claude/skills/' . $skill->name;
        $backupPath = null;

        if (is_dir($target)) {
            $backupPath = ($this->handleConflict)($target);
        }

        $this->filesystem->ensureDirectoryExists(dirname($target));
        $this->copyDirectory($skill->sourceDir, $target);

        return $backupPath;
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->filesystem->ensureDirectoryExists($target);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;

            if ($item->isDir()) {
                $this->filesystem->ensureDirectoryExists($destination);
                continue;
            }

            if (!@copy($item->getPathname(), $destination)) {
                throw new InstallFailedException(sprintf(
                    'Could not copy "%s" to "%s".',
                    $item->getPathname(),
                    $destination,
                ));
            }
        }
    }
}
