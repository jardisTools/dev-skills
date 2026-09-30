<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Exception\UninstallFailedException;
use JardisTools\DevSkills\Handler\Install\EnsureGeminiContext;

/**
 * Reverses what `EnsureGeminiContext` set in `.gemini/settings.json`, and only that: the
 * manifest holds the exact change, so foreign keys and formatting stay. If the plugin
 * created the file and only an empty scaffold is left after the reversal, the file
 * is deleted (and its folder, when that is empty now); otherwise the file stays without
 * the entry. No manifest note, no file, or a change that cannot be found any more:
 * nothing happens (the last case with a warning).
 */
final class RemoveGeminiContext
{
    /**
     * @param Closure(string, string, string): ?string $reverseEdit
     * @param Closure(string): bool                    $isEmptyScaffold
     * @param Closure(string, string): bool            $isLinkLeavingProject
     */
    public function __construct(
        private readonly Closure $reverseEdit,
        private readonly Closure $isEmptyScaffold,
        private readonly Closure $isLinkLeavingProject,
    ) {
    }

    public function __invoke(string $projectRoot, ?Manifest $manifest, UninstallReport $report): void
    {
        $entry = $manifest->selfSet[EnsureGeminiContext::FILE] ?? null;
        $target = $projectRoot . '/' . EnsureGeminiContext::FILE;
        if ($entry === null || !is_file($target) || ($this->isLinkLeavingProject)($projectRoot, $target)) {
            return;
        }

        $content = file_get_contents($target);
        $restored = $content === false ? null : ($this->reverseEdit)($content, $entry->before, $entry->after);
        if ($restored === null) {
            $report->addWarningIfAny(
                '.gemini/settings.json no longer holds the entry exactly as it was added; the file was left untouched.',
            );

            return;
        }

        if ($entry->fileCreated && ($this->isEmptyScaffold)($restored)) {
            if (!@unlink($target)) {
                throw new UninstallFailedException(sprintf('Could not delete "%s".', $target));
            }
            @rmdir(dirname($target));

            return;
        }
        if (@file_put_contents($target, $restored) === false) {
            throw new UninstallFailedException(sprintf('Could not remove the entry from "%s".', $target));
        }
    }
}
