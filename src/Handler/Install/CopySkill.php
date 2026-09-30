<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Composer\Util\Filesystem;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Exception\InstallFailedException;

final class CopySkill
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Copies the skill's source directory into `$destination` (created if
     * missing). Pure copy: existing content at the destination is neither
     * inspected nor removed, that is the job of the backup and swap closures.
     */
    public function __invoke(SkillDescriptor $skill, string $destination): void
    {
        $this->copyDirectory($skill->sourceDir, $destination);
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->filesystem->ensureDirectoryExists($target);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;

            if ($item->isDir()) {
                $this->filesystem->ensureDirectoryExists($destination);
                continue;
            }

            if (!@copy($item->getPathname(), $destination)) {
                throw new InstallFailedException(sprintf(
                    'Could not copy "%s" to "%s".',
                    $item->getPathname(),
                    $destination,
                ));
            }
        }
    }
}
