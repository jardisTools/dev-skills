<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Tests\Support;

final class TempProject
{
    public readonly string $root;

    public function __construct(string $prefix = 'dev-skills-test-')
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Could not allocate temp path.');
        }

        unlink($path);
        mkdir($path, 0o755, true);
        $this->root = $path;
    }

    public function mkdir(string $relative): string
    {
        $full = $this->root . '/' . ltrim($relative, '/');
        if (!is_dir($full) && !mkdir($full, 0o755, true) && !is_dir($full)) {
            throw new \RuntimeException('Could not create ' . $full);
        }

        return $full;
    }

    public function writeFile(string $relative, string $content): string
    {
        $full = $this->root . '/' . ltrim($relative, '/');
        $this->mkdir(dirname($relative));
        file_put_contents($full, $content);

        return $full;
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . ltrim($relative, '/');
    }

    public function cleanup(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->root);
    }
}
