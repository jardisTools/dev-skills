<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Exception\UninstallFailedException;

/**
 * Removes the reviewer shells the manifest lists and nothing else. A manifest key counts only when it is
 * a shell path of one of the five formats, and only when it names a regular file whose real path lies
 * inside the project: a link, a folder, or a key that points anywhere else is left alone. The
 * folders of the shells go when they are empty afterwards.
 */
final class RemoveReviewerShells
{
    public function __invoke(string $projectRoot, ?Manifest $manifest, UninstallReport $report): void
    {
        $realRoot = realpath($projectRoot);
        if ($realRoot === false) {
            return;
        }

        foreach ($manifest->selfSet ?? [] as $path => $entry) {
            $path = (string) $path;
            if (!$entry->fileCreated || ShellFormat::fromPath($path) === null) {
                continue;
            }

            $target = $realRoot . '/' . $path;
            $real = realpath($target);
            if (is_link($target) || !is_file($target) || $real === false || !str_starts_with($real, $realRoot . '/')) {
                continue;
            }
            if (!@unlink($target)) {
                throw new UninstallFailedException(sprintf('Could not delete the reviewer shell "%s".', $target));
            }

            $agents = dirname($target);
            if (@rmdir($agents)) {
                @rmdir(dirname($agents));
            }
        }
    }
}
