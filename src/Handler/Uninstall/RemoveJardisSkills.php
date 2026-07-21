<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Uninstall;

use Composer\Util\Filesystem;

final class RemoveJardisSkills
{
    /** @var list<string> */
    private const MANAGED_PREFIXES = [
        'adapter-',
        'core-',
        'support-',
        'tools-',
        'schema-',
        'plan-',
        'platform-',
        'rules-',
    ];

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * Removes skill directories whose name starts with a Jardis-managed prefix.
     *
     * Local skills without a Jardis prefix — and `<name>.backup/` sibling
     * directories created on prior installs — are left untouched.
     *
     * @return list<string>
     */
    public function __invoke(string $projectRoot): array
    {
        $skillsDir = $projectRoot . '/.claude/skills';
        if (!is_dir($skillsDir)) {
            return [];
        }

        $entries = glob($skillsDir . '/*', GLOB_ONLYDIR);
        if ($entries === false || $entries === []) {
            return [];
        }

        $removed = [];

        foreach ($entries as $entry) {
            $name = basename($entry);
            if (str_ends_with($name, '.backup')) {
                continue;
            }
            if (!$this->hasManagedPrefix($name)) {
                continue;
            }

            $this->filesystem->removeDirectory($entry);
            $removed[] = $name;
        }

        return $removed;
    }

    private function hasManagedPrefix(string $name): bool
    {
        foreach (self::MANAGED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
