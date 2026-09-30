<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\StagedSkill;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Swaps staged skills into place with `rename`. An existing target is moved
 * aside first and only deleted once the staged copy is in place; when the
 * swap fails, the previous target is restored and all remaining staging
 * directories are removed.
 */
final class CommitStagedSkills
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<StagedSkill> $staged
     */
    public function __invoke(array $staged): void
    {
        try {
            foreach ($staged as $item) {
                $this->swap($item);
            }
        } catch (\Throwable $failure) {
            foreach ($staged as $item) {
                $this->filesystem->remove($item->stagingDir);
            }

            throw $failure;
        }
    }

    private function swap(StagedSkill $item): void
    {
        $aside = $item->stagingDir . '.old';
        $this->filesystem->remove($aside);
        $hadTarget = is_dir($item->targetDir);

        if ($hadTarget && !@rename($item->targetDir, $aside)) {
            throw new InstallFailedException(sprintf('Could not move "%s" aside.', $item->targetDir));
        }

        if (!@rename($item->stagingDir, $item->targetDir)) {
            if ($hadTarget) {
                @rename($aside, $item->targetDir);
            }

            throw new InstallFailedException(sprintf('Could not move staged skill into "%s".', $item->targetDir));
        }

        $this->filesystem->remove($aside);
    }
}
