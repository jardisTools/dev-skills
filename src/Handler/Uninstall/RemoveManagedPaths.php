<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Exception\UninstallFailedException;

/**
 * Removes exactly what the plugin manages, never anything by name pattern of
 * the bundle areas:
 *
 * - healthy manifest: the skill folders it lists (in every target folder), then the manifest;
 * - no manifest (1.3.x install): the fixed list of the old bundle names plus vendor skills
 *   under the package prefixes, in `.claude/skills` only (1.3.x wrote nowhere else);
 * - defective or too new manifest: nothing.
 *
 * `.claude/.jardis-backup/` and all folders of the user stay.
 */
final class RemoveManagedPaths
{
    private const SKILLS_DIR = '.claude/skills';
    private const SKILLS_FOLDER_NAME = 'skills';

    /** @var list<string> */
    private const VENDOR_PREFIXES = ['adapter-', 'core-', 'support-', 'tools-'];

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * @return list<string> names of the removed skills, each once
     */
    public function __invoke(string $projectRoot, ManifestReadResult $manifest): array
    {
        $realRoot = realpath($projectRoot);
        if ($realRoot === false) {
            return [];
        }

        return match ($manifest->state) {
            ManifestState::Healthy => $this->removeManifestPaths($realRoot, $manifest->manifest->entries ?? []),
            ManifestState::Missing => $this->removeByFixedList($realRoot),
            ManifestState::Defective, ManifestState::TooNew => [],
        };
    }

    /**
     * @param array<string, array{source: string, sha256: string}> $entries
     * @return list<string>
     */
    private function removeManifestPaths(string $realRoot, array $entries): array
    {
        $removed = [];

        foreach (array_keys($entries) as $key) {
            if (!$this->isSkillFolderKey($key)) {
                continue;
            }
            $path = str_starts_with($key, '/') ? $key : $realRoot . '/' . $key;
            if (!is_dir($path)) {
                continue;
            }

            $this->remove($path);
            $removed[basename($key)] = basename($key);
        }

        $manifestFile = $realRoot . '/' . Manifest::FILE;
        if (is_file($manifestFile) && !@unlink($manifestFile)) {
            throw new UninstallFailedException(sprintf('Could not delete the manifest "%s".', $manifestFile));
        }

        return array_values($removed);
    }

    /**
     * @return list<string>
     */
    private function removeByFixedList(string $realRoot): array
    {
        $skillsDir = $realRoot . '/' . self::SKILLS_DIR;
        $removed = [];

        foreach (array_keys(RenamedSkills::MAPPING) as $name) {
            if (is_dir($skillsDir . '/' . $name)) {
                $this->remove($skillsDir . '/' . $name);
                $removed[$name] = $name;
            }
        }

        foreach (glob($skillsDir . '/*', GLOB_ONLYDIR) ?: [] as $entry) {
            $name = basename($entry);
            if ($this->hasVendorPrefix($name) && !str_ends_with($name, '.backup')) {
                $this->remove($entry);
                $removed[$name] = $name;
            }
        }

        return array_values($removed);
    }

    /**
     * A manifest key must point at a folder directly inside a `skills` folder
     * and must not climb out of the project (a tampered manifest deletes nothing else).
     */
    private function isSkillFolderKey(string $key): bool
    {
        return !in_array('..', explode('/', $key), true) && basename(dirname($key)) === self::SKILLS_FOLDER_NAME;
    }

    private function hasVendorPrefix(string $name): bool
    {
        foreach (self::VENDOR_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function remove(string $path): void
    {
        if (!$this->filesystem->removeDirectory($path)) {
            throw new UninstallFailedException(sprintf('Could not remove skill folder "%s".', $path));
        }
    }
}
