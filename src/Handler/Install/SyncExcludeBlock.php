<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\ExcludeFileLocation;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\ProcessDocsMode;
use JardisTools\DevSkills\Exception\InstallFailedException;

/**
 * Keeps the plugin's marked block in the Git exclude file in step with `process-docs` (R20): the
 * block is there in both modes (backup folder); `local` adds the process documents and the files
 * the plugin creates. A run writes the block new, so a switch between the modes converges.
 * `.gitignore` is never touched, nothing is staged, committed or untracked. Files Git already
 * tracks cannot be hidden by an exclude entry: the run lists them in a warning.
 *
 * Without a repository at the project root, or with the project below the top of a larger work
 * tree, nothing is written and the run warns, in both modes (an exclude pattern would be relative
 * to another directory; nothing may land in a customer repository unnoticed). Anything else that goes
 * wrong (the exclude path is a directory, corrupt markers, a write error) throws; the add-on
 * decorator turns that into a warning. Runs last, so the manifest lists everything the run installed.
 */
final class SyncExcludeBlock
{
    private const LISTED_TRACKED_FILES = 20;

    /**
     * @param Closure(string): ExcludeFileLocation   $resolveGitDir
     * @param Closure(string, string): ManifestReadResult $readManifest
     * @param Closure(ProcessDocsMode, ?Manifest): list<string> $buildLines
     * @param Closure(string, ?list<string>): string   $replaceBlock
     * @param Closure(string, list<string>): list<string> $listTrackedPaths
     */
    public function __construct(
        private readonly ProcessDocsMode $mode,
        private readonly Closure $resolveGitDir,
        private readonly Closure $readManifest,
        private readonly Closure $buildLines,
        private readonly Closure $replaceBlock,
        private readonly Closure $listTrackedPaths,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $location = ($this->resolveGitDir)($projectRoot);
        $exclude = $location->path;
        if ($exclude === null) {
            $report->addWarning($this->noExcludeFileWarning($location));

            return;
        }
        if (file_exists($exclude) && !is_file($exclude)) {
            throw new InstallFailedException(sprintf('"%s" exists but is not a regular file.', $exclude));
        }

        $read = ($this->readManifest)($projectRoot . '/' . Manifest::FILE, '0.0.0');
        $manifest = $read->state === ManifestState::Healthy ? $read->manifest : null;
        $lines = ($this->buildLines)($this->mode, $manifest);

        $existing = is_file($exclude) ? file_get_contents($exclude) : '';
        if ($existing === false) {
            throw new InstallFailedException(sprintf('Could not read "%s".', $exclude));
        }
        $updated = ($this->replaceBlock)($existing, $lines);
        if ($updated !== $existing) {
            $this->write($exclude, $updated);
        }

        if ($this->mode === ProcessDocsMode::Local) {
            $this->warnAboutTrackedFiles($projectRoot, $lines, $report);
        }
    }

    private function noExcludeFileWarning(ExcludeFileLocation $location): string
    {
        $where = $location->enclosingWorkTree === null
            ? 'no Git repository at the project root'
            : sprintf(
                'the project lies inside the Git work tree "%s", not at its root',
                $location->enclosingWorkTree,
            );

        return sprintf('%s; the exclude block was not written (process-docs=%s).', $where, $this->mode->value);
    }

    private function write(string $exclude, string $content): void
    {
        $directory = dirname($exclude);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new InstallFailedException(sprintf('Could not create "%s".', $directory));
        }
        if (@file_put_contents($exclude, $content) === false) {
            throw new InstallFailedException(sprintf('Could not write the exclude block to "%s".', $exclude));
        }
    }

    /**
     * @param list<string> $lines
     */
    private function warnAboutTrackedFiles(string $projectRoot, array $lines, InstallReport $report): void
    {
        $tracked = ($this->listTrackedPaths)($projectRoot, $lines);
        if ($tracked === []) {
            return;
        }

        $shown = array_slice($tracked, 0, self::LISTED_TRACKED_FILES);
        $more = count($tracked) - count($shown);
        $report->addWarning(sprintf(
            'process-docs=local: Git already tracks these files, so the exclude block cannot hide them;'
            . ' the plugin never untracks (use "git rm --cached" yourself): %s%s',
            implode(', ', $shown),
            $more > 0 ? sprintf(' and %d more', $more) : '',
        ));
    }
}
