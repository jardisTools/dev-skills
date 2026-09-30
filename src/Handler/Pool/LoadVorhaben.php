<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Pool;

use JardisTools\DevSkills\Data\VorhabenFile;

/**
 * Loads the files of the work folders of a project: `PROGRESS.md`, `PLAN.md` and every `PLAN-E*.md` directly in
 * each folder `<root>/docs/vorhaben/<name>/`, in name order. A link is never followed: a folder behind a link
 * counts as missing, a linked folder or file is left out. No such folder is no error, it yields no files.
 */
final class LoadVorhaben
{
    public const VORHABEN_DIR = 'docs/vorhaben';

    public function __construct(
        private readonly ResolvePath $resolvePath = new ResolvePath(),
    ) {
    }

    /**
     * @return list<VorhabenFile>
     */
    public function __invoke(string $root): array
    {
        $base = ($this->resolvePath)($root, self::VORHABEN_DIR);
        if ($base === null || !is_dir($base)) {
            return [];
        }

        $files = [];
        foreach ($this->names($base) as $folder) {
            $folderPath = $base . '/' . $folder;
            if (is_link($folderPath) || !is_dir($folderPath)) {
                continue;
            }
            foreach ($this->names($folderPath) as $name) {
                $kind = $this->kindOf($name);
                $path = $folderPath . '/' . $name;
                if ($kind === null || is_link($path) || !is_file($path)) {
                    continue;
                }
                $content = (string) file_get_contents($path);
                $lines   = preg_split('/\r\n|\n|\r/', $content);
                $lines   = $lines === false ? [] : $lines;
                if ($lines !== [] && end($lines) === '') {
                    array_pop($lines);
                }
                $files[] = new VorhabenFile(
                    self::VORHABEN_DIR . '/' . $folder . '/' . $name,
                    $kind,
                    strlen($content),
                    $lines,
                );
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function names(string $directory): array
    {
        $names = scandir($directory);
        $names = $names === false ? [] : array_values(array_diff($names, ['.', '..']));
        sort($names);

        return $names;
    }

    private function kindOf(string $name): ?string
    {
        return match (true) {
            $name === 'PROGRESS.md' => VorhabenFile::KIND_PROGRESS,
            $name === 'PLAN.md' => VorhabenFile::KIND_PLAN,
            str_starts_with($name, 'PLAN-E') && str_ends_with($name, '.md') => VorhabenFile::KIND_STAGE_PLAN,
            default => null,
        };
    }
}
