<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\SelfSetEntry;

/**
 * Writes the manifest atomically: temp file in the target directory, then
 * rename. The optional `selfSet` field is written only when it holds something.
 * A failure leaves an existing manifest untouched and no temp file behind.
 */
final class WriteManifest
{
    public function __invoke(string $path, Manifest $manifest): void
    {
        $paths = $manifest->entries;
        ksort($paths, SORT_STRING);

        $document = [
            'schemaVersion' => $manifest->schemaVersion,
            'pluginVersion' => $manifest->pluginVersion,
            'paths' => (object) $paths,
        ];
        if ($manifest->selfSet !== []) {
            $selfSet = $manifest->selfSet;
            ksort($selfSet, SORT_STRING);
            $document['selfSet'] = array_map(
                static fn (SelfSetEntry $entry): array => [
                    'fileCreated' => $entry->fileCreated,
                    'before' => $entry->before,
                    'after' => $entry->after,
                ],
                $selfSet,
            );
        }

        $json = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create ' . $directory);
        }

        $temp = $directory . '/.jardis-managed.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $json) === false) {
            @unlink($temp);
            throw new \RuntimeException('Could not write ' . $temp);
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not replace ' . $path);
        }
    }
}
