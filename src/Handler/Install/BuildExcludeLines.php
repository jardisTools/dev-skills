<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ProcessDocsMode;

/**
 * The lines of the plugin's exclude block. In both modes the block holds the backup folder, which
 * is never meant for a commit. `local` adds the process document folders, the paths of the
 * manifest, the manifest itself, and the files the plugin set something in only when it created them
 * (AGENTS.md, CLAUDE.md, the Gemini settings).
 * Every line is a plain path (no pattern characters), so it can also serve as a Git pathspec;
 * a manifest path that is not a plain relative path is left out.
 */
final class BuildExcludeLines
{
    public const BACKUP_FOLDER = '.claude/.jardis-backup/';

    private const PROCESS_FOLDERS = ['docs/vorhaben/', 'docs/digests/', '.claude/wissen/'];

    /**
     * @return list<string>
     */
    public function __invoke(ProcessDocsMode $mode, ?Manifest $manifest): array
    {
        $lines = [self::BACKUP_FOLDER];
        if ($mode === ProcessDocsMode::Committed) {
            return $lines;
        }

        $lines = [...$lines, ...self::PROCESS_FOLDERS];

        $paths = array_map('strval', array_keys($manifest->entries ?? []));
        sort($paths, SORT_STRING);
        foreach ([...$paths, Manifest::FILE] as $path) {
            if ($this->isPlainRelativePath($path)) {
                $lines[] = $path;
            }
        }

        $selfSet = $manifest->selfSet ?? [];
        ksort($selfSet, SORT_STRING);
        foreach ($selfSet as $file => $entry) {
            if ($entry->fileCreated && $this->isPlainRelativePath((string) $file)) {
                $lines[] = '/' . $file;
            }
        }

        return array_values(array_unique($lines));
    }

    private function isPlainRelativePath(string $path): bool
    {
        return preg_match('#^[A-Za-z0-9._][A-Za-z0-9._/-]*$#', $path) === 1
            && !str_contains($path, '..')
            && !str_ends_with($path, '/');
    }
}
