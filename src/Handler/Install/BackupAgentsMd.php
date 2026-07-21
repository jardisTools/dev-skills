<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class BackupAgentsMd
{
    /** @var Closure(string): bool */
    private readonly Closure $unlink;

    /** @var Closure(string, string): bool */
    private readonly Closure $rename;

    public function __construct(
        ?Closure $unlink = null,
        ?Closure $rename = null,
    ) {
        $this->unlink = $unlink ?? static fn (string $path): bool => @unlink($path);
        $this->rename = $rename ?? static fn (string $from, string $to): bool => @rename($from, $to);
    }

    /**
     * Moves an existing AGENTS.md out of the way to a `.backup` sibling.
     * A stale `.backup` from a previous run is replaced. Returns the
     * absolute path of the created backup file.
     */
    public function __invoke(string $filePath): string
    {
        $backupPath = $filePath . '.backup';

        if (is_file($backupPath) && !($this->unlink)($backupPath)) {
            throw new InstallFailedException(sprintf(
                'Could not remove stale AGENTS.md backup at "%s".',
                $backupPath,
            ));
        }

        if (!($this->rename)($filePath, $backupPath)) {
            throw new InstallFailedException(sprintf(
                'Could not move existing AGENTS.md "%s" to backup "%s".',
                $filePath,
                $backupPath,
            ));
        }

        return $backupPath;
    }
}
