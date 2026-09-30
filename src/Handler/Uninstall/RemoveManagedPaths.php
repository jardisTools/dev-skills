<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Closure;
use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\UninstallReport;
use JardisTools\DevSkills\Exception\UninstallFailedException;
use JardisTools\DevSkills\Handler\Install\ResolveTargets;

/**
 * Removes exactly what the plugin manages, never anything by name pattern of
 * the bundle areas:
 *
 * - healthy manifest: the skill folders it lists (in every target folder), then the manifest.
 *   A key is honoured only as a relative `.claude/skills/<name>` or `.agents/skills/<name>` of the
 *   project whose real path is exactly that location; anything else (absolute, `..`, foreign folder,
 *   symlink) is ignored with a warning;
 * - no manifest: the fixed list of the 18 old bundle names and the 18 names they carry now (both
 *   from RenamedSkills::MAPPING) plus vendor skills under the package prefixes in `.claude/skills`
 *   (1.3.x wrote nowhere else); in `.agents/skills` only the 18 current names, because that
 *   folder never held an old name (no release writes one there) and the vendor-prefix rule stays
 *   `.claude/skills`-only. Never a prefix match on the bundle areas.
 *   Every name, in both folders, passes the same ResolveManagedFolder rule as a manifest key:
 *   a symlinked `.claude/skills` or `.agents/skills` folder, or a symlinked skill folder in
 *   them, is never followed;
 * - defective or too new manifest: nothing.
 *
 * `.claude/.jardis-backup/` and all folders of the user stay.
 */
final class RemoveManagedPaths
{
    private const SKILLS_DIR = '.claude/skills';

    /** @var list<string> */
    private const VENDOR_PREFIXES = ['adapter-', 'core-', 'support-', 'tools-'];

    /**
     * @param Closure(string, string): ?string $resolveManagedFolder
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Closure $resolveManagedFolder,
    ) {
    }

    /**
     * @return list<string> names of the removed skills, each once
     */
    public function __invoke(string $projectRoot, ManifestReadResult $manifest, UninstallReport $report): array
    {
        $realRoot = realpath($projectRoot);
        if ($realRoot === false) {
            return [];
        }

        return match ($manifest->state) {
            ManifestState::Healthy => $this->removeManifestPaths(
                $realRoot,
                $manifest->manifest->entries ?? [],
                $report,
            ),
            ManifestState::Missing => $this->removeByFixedList($realRoot),
            ManifestState::Defective, ManifestState::TooNew => [],
        };
    }

    /**
     * @param array<string, array{source: string, sha256: string}> $entries
     * @return list<string>
     */
    private function removeManifestPaths(string $realRoot, array $entries, UninstallReport $report): array
    {
        $removed = [];

        foreach (array_keys($entries) as $key) {
            $key = (string) $key;
            $path = ($this->resolveManagedFolder)($realRoot, $key);
            if ($path === null) {
                $report->addWarningIfAny(sprintf(
                    'Ignored manifest entry "%s": not a skill folder inside the project.',
                    $key,
                ));
                continue;
            }
            if ($path === '') {
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

        foreach ([...array_keys(RenamedSkills::MAPPING), ...array_values(RenamedSkills::MAPPING)] as $name) {
            if ($this->removeIfManaged($realRoot, self::SKILLS_DIR . '/' . $name)) {
                $removed[$name] = $name;
            }
        }

        $agentsKey = ResolveTargets::AGENTS_SKILLS_DIR;
        foreach (array_values(RenamedSkills::MAPPING) as $name) {
            if ($this->removeIfManaged($realRoot, $agentsKey . '/' . $name)) {
                $removed[$name] = $name;
            }
        }

        // Only a real `.claude/skills` folder is scanned; a symlinked one is never listed.
        $entries = realpath($skillsDir) === $skillsDir && is_dir($skillsDir)
            ? (glob($skillsDir . '/*', GLOB_ONLYDIR) ?: [])
            : [];
        foreach ($entries as $entry) {
            $name = basename($entry);
            if (!$this->hasVendorPrefix($name) || str_ends_with($name, '.backup')) {
                continue;
            }
            if ($this->removeIfManaged($realRoot, self::SKILLS_DIR . '/' . $name)) {
                $removed[$name] = $name;
            }
        }

        return array_values($removed);
    }

    /**
     * The only way a skill name becomes a file system path without a manifest: via the managed-folder rule.
     *
     * @return bool true when a folder was removed
     */
    private function removeIfManaged(string $realRoot, string $key): bool
    {
        $folder = ($this->resolveManagedFolder)($realRoot, $key);
        if ($folder === null || $folder === '') {
            return false;
        }
        $this->remove($folder);

        return true;
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
