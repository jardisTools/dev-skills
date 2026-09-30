<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Closure;
use JardisTools\DevSkills\Data\ExcludeFileLocation;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Exception\UninstallFailedException;

/**
 * Takes the plugin's whole marked block out of the Git exclude file (R14), backup folder included.
 * Everything outside the block stays byte for byte. No repository, no exclude file or no block:
 * nothing to do. Corrupt markers or a write error throw; the add-on decorator turns that into a warning.
 */
final class RemoveExcludeBlock
{
    /**
     * @param Closure(string): ExcludeFileLocation   $resolveGitDir
     * @param Closure(string, ?list<string>): string   $replaceBlock
     */
    public function __construct(
        private readonly Closure $resolveGitDir,
        private readonly Closure $replaceBlock,
    ) {
    }

    public function __invoke(string $projectRoot, ?Manifest $manifest, UninstallReport $report): void
    {
        $exclude = ($this->resolveGitDir)($projectRoot)->path;
        if ($exclude === null || !is_file($exclude)) {
            return;
        }

        $existing = file_get_contents($exclude);
        if ($existing === false) {
            throw new UninstallFailedException(sprintf('Could not read "%s".', $exclude));
        }

        $updated = ($this->replaceBlock)($existing, null);
        if ($updated !== $existing && @file_put_contents($exclude, $updated) === false) {
            throw new UninstallFailedException(sprintf('Could not remove the exclude block from "%s".', $exclude));
        }
    }
}
