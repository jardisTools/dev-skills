<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class HandleConflict
{
    /** @var Closure(string, string): bool */
    private readonly Closure $rename;

    public function __construct(
        private readonly Filesystem $filesystem,
        ?Closure $rename = null,
    ) {
        $this->rename = $rename ?? static fn (string $from, string $to): bool => @rename($from, $to);
    }

    /**
     * Moves an existing skill directory out of the way to a `.backup` sibling.
     *
     * Returns the absolute path of the created backup directory.
     */
    public function __invoke(string $existingTargetDir): string
    {
        $backupPath = $existingTargetDir . '.backup';

        if (is_dir($backupPath)) {
            $this->filesystem->removeDirectory($backupPath);
        }

        if (!($this->rename)($existingTargetDir, $backupPath)) {
            throw new InstallFailedException(sprintf(
                'Could not move existing skill directory "%s" to backup "%s".',
                $existingTargetDir,
                $backupPath,
            ));
        }

        return $backupPath;
    }
}
