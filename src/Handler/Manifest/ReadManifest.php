<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;

/**
 * Reads the manifest file and classifies it. Never throws on a broken file:
 * invalid JSON or schema yields Defective, a newer schema or plugin version
 * yields TooNew, an absent file yields Missing.
 */
final class ReadManifest
{
    public function __invoke(
        string $path,
        string $currentPluginVersion,
        int $supportedSchemaVersion = Manifest::SCHEMA_VERSION,
    ): ManifestReadResult {
        if (!is_file($path)) {
            return new ManifestReadResult(ManifestState::Missing);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return $this->defective('manifest is not readable');
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->defective('manifest is not valid JSON');
        }

        if (
            !is_array($data)
            || !is_int($data['schemaVersion'] ?? null)
            || !is_string($data['pluginVersion'] ?? null)
        ) {
            return $this->defective('manifest lacks schemaVersion or pluginVersion');
        }

        $schemaVersion = $data['schemaVersion'];
        $pluginVersion = $data['pluginVersion'];

        if (
            $schemaVersion > $supportedSchemaVersion
            || version_compare($pluginVersion, $currentPluginVersion, '>')
        ) {
            return new ManifestReadResult(
                ManifestState::TooNew,
                new Manifest($schemaVersion, $pluginVersion),
                sprintf(
                    'manifest was written by a newer version (schema %d, plugin %s); '
                    . 'supported are schema %d and plugin %s',
                    $schemaVersion,
                    $pluginVersion,
                    $supportedSchemaVersion,
                    $currentPluginVersion,
                ),
            );
        }

        $entries = $this->parseEntries($data['paths'] ?? null);
        if ($entries === null) {
            return $this->defective('manifest paths are malformed');
        }

        return new ManifestReadResult(
            ManifestState::Healthy,
            new Manifest($schemaVersion, $pluginVersion, $entries),
        );
    }

    /**
     * @return array<string, array{source: string, sha256: string}>|null
     */
    private function parseEntries(mixed $paths): ?array
    {
        if (!is_array($paths)) {
            return null;
        }

        $entries = [];
        foreach ($paths as $key => $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['source'] ?? null)
                || !is_string($entry['sha256'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) !== 1
            ) {
                return null;
            }
            $entries[(string) $key] = ['source' => $entry['source'], 'sha256' => $entry['sha256']];
        }

        return $entries;
    }

    private function defective(string $reason): ManifestReadResult
    {
        return new ManifestReadResult(ManifestState::Defective, null, 'Ignoring defective manifest: ' . $reason . '.');
    }
}
