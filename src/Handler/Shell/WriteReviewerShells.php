<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use Closure;
use JardisTools\DevSkills\Data\InstallReport;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\ReviewerSource;
use JardisTools\DevSkills\Data\SelfSetEntry;
use JardisTools\DevSkills\Data\ShellFormat;
use JardisTools\DevSkills\Exception\InstallFailedException;
use JardisTools\DevSkills\Handler\Manifest\ResolvePluginVersion;

/**
 * Writes the reviewer shells (R8): for every source `skills/process-review-board/reviewers/<role>.md`
 * of the plugin one shell per tool format. No source, no shell; a source without valid frontmatter
 * yields a warning and no shell. Without the source folder (it does not exist before the prompts are
 * shipped) nothing happens and nothing is reported.
 *
 * A shell is overwritten only when the manifest lists it (`selfSet`, `fileCreated`). Anything else at the
 * target path (a foreign file, a folder, a link) stays as it is and is reported. A write error for one
 * shell is a warning as well; the other shells are still written. A new shell is noted in the manifest
 * after it was written: an interrupted run leaves a file the next run treats as foreign, never the
 * other way round. Never through a link: a link at the target or in a folder on the way (`.codex`,
 * `.codex/agents`) is left alone, nothing is written and no folder is created behind it; the check runs
 * before the first `mkdir`, and the real path of the folder is checked once more afterwards.
 */
final class WriteReviewerShells
{
    public const SOURCE_DIR = 'skills/process-review-board/reviewers';

    /**
     * @param Closure(string, string): ?ReviewerSource $parseSource
     * @param Closure(ReviewerSource, ShellFormat): string $renderShell
     * @param Closure(string, string): ManifestReadResult $readManifest
     * @param Closure(string, string, SelfSetEntry): void $recordSelfSet
     * @param Closure(string, string): bool $isPathBehindLink
     */
    public function __construct(
        private readonly string $pluginRoot,
        private readonly Closure $parseSource,
        private readonly Closure $renderShell,
        private readonly Closure $readManifest,
        private readonly Closure $recordSelfSet,
        private readonly Closure $isPathBehindLink,
    ) {
    }

    public function __invoke(string $projectRoot, string $vendorDir, InstallReport $report): void
    {
        $sourceDir = $this->pluginRoot . '/' . self::SOURCE_DIR;
        if (!is_dir($sourceDir)) {
            return;
        }

        $realRoot = realpath($projectRoot);
        if ($realRoot === false) {
            throw new InstallFailedException(sprintf('Could not resolve the project root "%s".', $projectRoot));
        }

        $managed = $this->managedShells($projectRoot);
        $names = array_filter(
            scandir($sourceDir) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.md') && is_file($sourceDir . '/' . $name),
        );
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            $role = basename($name, '.md');
            $content = file_get_contents($sourceDir . '/' . $name);
            $source = $content === false ? null : ($this->parseSource)($role, $content);
            if ($source === null) {
                $report->addWarning(sprintf(
                    'Reviewer source "%s" has no valid frontmatter (name equal to the file name, description);'
                    . ' no shell written.',
                    $name,
                ));
                continue;
            }

            $this->writeShellsOf($source, $projectRoot, $realRoot, $managed, $report);
        }
    }

    /**
     * @param array<string, true> $managed
     */
    private function writeShellsOf(
        ReviewerSource $source,
        string $projectRoot,
        string $realRoot,
        array $managed,
        InstallReport $report,
    ): void {
        foreach (ShellFormat::cases() as $format) {
            try {
                $warning = $this->writeShell(
                    $projectRoot,
                    $realRoot,
                    $format->pathFor($source->role),
                    ($this->renderShell)($source, $format),
                    $managed,
                );
            } catch (InstallFailedException $failure) {
                $warning = $failure->getMessage();
            }
            $report->addWarningIfAny($warning);
        }
    }

    /**
     * @return array<string, true> shell paths the manifest lists
     */
    private function managedShells(string $projectRoot): array
    {
        $read = ($this->readManifest)($projectRoot . '/' . Manifest::FILE, ResolvePluginVersion::DEV_VERSION);
        if ($read->state !== ManifestState::Healthy) {
            return [];
        }

        $managed = [];
        foreach ($read->manifest->selfSet ?? [] as $path => $entry) {
            if ($entry->fileCreated && ShellFormat::fromPath((string) $path) !== null) {
                $managed[(string) $path] = true;
            }
        }

        return $managed;
    }

    /**
     * @param array<string, true> $managed
     * @return string|null a warning when the shell was left as it was
     */
    private function writeShell(
        string $projectRoot,
        string $realRoot,
        string $path,
        string $content,
        array $managed,
    ): ?string {
        $target = $projectRoot . '/' . $path;
        $isManaged = isset($managed[$path]);

        if (is_link($target) || (file_exists($target) && (!$isManaged || !is_file($target)))) {
            return sprintf(
                '%s exists and is not a reviewer shell of the plugin; the file was left unchanged.',
                $path,
            );
        }
        if ($isManaged && is_file($target) && file_get_contents($target) === $content) {
            return null;
        }

        $this->ensureDirectory(dirname($target), $projectRoot, $realRoot);
        if (@file_put_contents($target, $content) === false) {
            throw new InstallFailedException(sprintf('Could not write the reviewer shell "%s".', $path));
        }

        if (!$isManaged) {
            try {
                ($this->recordSelfSet)($projectRoot, $path, new SelfSetEntry(true));
            } catch (\Exception $failure) {
                @unlink($target);
                throw new InstallFailedException(sprintf(
                    'Could not note the reviewer shell "%s" in the manifest: %s',
                    $path,
                    $failure->getMessage(),
                ));
            }
        }

        return null;
    }

    private function ensureDirectory(string $directory, string $projectRoot, string $realRoot): void
    {
        if (($this->isPathBehindLink)($projectRoot, $directory)) {
            throw new InstallFailedException(
                sprintf('The folder "%s" is a link or lies behind one; nothing was written.', $directory),
            );
        }

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new InstallFailedException(sprintf('Could not create the folder "%s".', $directory));
        }

        $real = realpath($directory);
        if ($real === false || !str_starts_with($real, $realRoot . '/')) {
            throw new InstallFailedException(
                sprintf('The folder "%s" leads out of the project; nothing was written.', $directory),
            );
        }
    }
}
