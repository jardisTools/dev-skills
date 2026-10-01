<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Manifest;

use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\ManifestReadResult;
use JardisTools\DevSkills\Data\ManifestState;
use JardisTools\DevSkills\Data\SelfSetEntry;

/**
 * Reads the manifest file and classifies it. Never throws on a broken file:
 * invalid JSON or schema yields Defective, a newer schema or plugin version
 * yields TooNew, an absent file yields Missing.
 *
 * A dev checkout resolves to ResolvePluginVersion::DEV_VERSION and has no
 * comparable release number: for it only the schema version decides, so a
 * manifest written by a release is not mistaken for "too new".
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
            || (
                $currentPluginVersion !== ResolvePluginVersion::DEV_VERSION
                && version_compare($pluginVersion, $currentPluginVersion, '>')
            )
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

        $selfSet = $this->parseSelfSet($data['selfSet'] ?? []);
        if ($selfSet === null) {
            return $this->defective('manifest selfSet is malformed');
        }

        return new ManifestReadResult(
            ManifestState::Healthy,
            new Manifest($schemaVersion, $pluginVersion, $entries, $selfSet),
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
            // PHP turns digit-only object keys and list indexes into int keys; neither names a skill folder.
            if (
                !is_string($key)
                || !is_array($entry)
                || !is_string($entry['source'] ?? null)
                || !is_string($entry['sha256'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) !== 1
            ) {
                return null;
            }
            $entries[$key] = ['source' => $entry['source'], 'sha256' => $entry['sha256']];
        }

        return $entries;
    }

    /**
     * @return array<string, SelfSetEntry>|null
     */
    private function parseSelfSet(mixed $selfSet): ?array
    {
        if (!is_array($selfSet)) {
            return null;
        }

        $parsed = [];
        foreach ($selfSet as $key => $entry) {
            if (
                !is_string($key)
                || !is_array($entry)
                || !is_bool($entry['fileCreated'] ?? null)
                || !is_string($entry['before'] ?? null)
                || !is_string($entry['after'] ?? null)
            ) {
                return null;
            }
            $parsed[$key] = new SelfSetEntry($entry['fileCreated'], $entry['before'], $entry['after']);
        }

        return $parsed;
    }

    private function defective(string $reason): ManifestReadResult
    {
        return new ManifestReadResult(ManifestState::Defective, null, 'Ignoring defective manifest: ' . $reason . '.');
    }
}
