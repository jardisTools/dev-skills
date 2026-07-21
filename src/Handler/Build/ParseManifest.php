<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Build;

use JardisTools\DevSkills\Data\CatalogEntry;

/**
 * Reads catalog/manifest.json and returns a deterministically-sorted
 * list of CatalogEntry value objects. Throws RuntimeException on any
 * IO, JSON, or schema error; missing/empty required fields are rejected
 * before the entry reaches the CatalogEntry constructor.
 */
final class ParseManifest
{
    /**
     * @return list<CatalogEntry>
     */
    public function __invoke(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Manifest file not found: %s', $path));
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException(sprintf('Cannot read manifest file: %s', $path));
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                sprintf('Manifest is not valid JSON: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Manifest root must be a JSON array.');
        }

        $entries = [];
        $index   = 0;

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException(
                    sprintf('Manifest entry at index %d must be a JSON object.', $index),
                );
            }

            /** @var array<string, mixed> $item */
            $package         = $this->requireString($item, 'package', $index);
            $capability      = $this->requireString($item, 'capability', $index);
            $useWhen         = $this->requireString($item, 'use_when', $index);
            $composerRequire = $this->requireString($item, 'composer_require', $index);

            $rawAlternatives = $item['alternatives'] ?? null;
            $alternatives    = is_string($rawAlternatives) && $rawAlternatives !== ''
                ? $rawAlternatives
                : null;

            $entries[] = new CatalogEntry(
                package: $package,
                capability: $capability,
                useWhen: $useWhen,
                composerRequire: $composerRequire,
                alternatives: $alternatives,
            );

            $index++;
        }

        usort(
            $entries,
            static fn (CatalogEntry $a, CatalogEntry $b): int => strcmp($a->package, $b->package),
        );

        return $entries;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function requireString(array $item, string $field, int $index): string
    {
        if (!array_key_exists($field, $item)) {
            throw new \RuntimeException(
                sprintf('Manifest entry %d is missing required field "%s".', $index, $field),
            );
        }

        $value = $item[$field];

        if (!is_string($value) || $value === '') {
            throw new \RuntimeException(
                sprintf(
                    'Manifest entry %d field "%s" must be a non-empty string.',
                    $index,
                    $field,
                ),
            );
        }

        return $value;
    }
}
