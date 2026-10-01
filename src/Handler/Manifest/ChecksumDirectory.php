<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

/**
 * Computes a deterministic SHA-256 over a directory tree. Byte-based (no line
 * ending normalisation), files sorted by relative path, directories not followed
 * through symlinks. The relative path is part of the hash, so renames change it.
 */
final class ChecksumDirectory
{
    public function __invoke(string $directory): string
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException('Not a directory: ' . $directory);
        }

        $base = rtrim($directory, '/');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $files[substr($item->getPathname(), strlen($base) + 1)] = $item->getPathname();
            }
        }
        ksort($files, SORT_STRING);

        $context = hash_init('sha256');
        foreach ($files as $relative => $full) {
            $fileHash = hash_file('sha256', $full);
            if ($fileHash === false) {
                throw new \RuntimeException('Could not read ' . $full);
            }
            hash_update($context, $relative . "\0" . $fileHash . "\n");
        }

        return hash_final($context);
    }
}
