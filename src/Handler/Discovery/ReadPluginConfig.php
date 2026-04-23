<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Exception\InvalidPluginConfigException;

final class ReadPluginConfig
{
    private const ROOT_KEY = 'jardis/dev-skills';
    private const BUNDLED_KEY = 'bundled-skills';

    /**
     * @param array<string, mixed> $extra the value returned by
     *                                    $composer->getPackage()->getExtra()
     */
    public function __invoke(array $extra): PluginConfig
    {
        $root = $extra[self::ROOT_KEY] ?? null;
        if (!is_array($root) || !array_key_exists(self::BUNDLED_KEY, $root)) {
            return PluginConfig::none();
        }

        $raw = $root[self::BUNDLED_KEY];

        if ($raw === true) {
            return PluginConfig::all();
        }
        if ($raw === false) {
            return PluginConfig::none();
        }

        try {
            if (is_array($raw) && array_is_list($raw)) {
                return $this->fromList($raw);
            }

            if (is_array($raw)) {
                return $this->fromMap($raw);
            }

            throw new InvalidPluginConfigException(sprintf(
                'bundled-skills must be bool, array of globs, or {include,exclude} object;'
                . ' got %s.',
                get_debug_type($raw),
            ));
        } catch (InvalidPluginConfigException $e) {
            return PluginConfig::invalid(
                $e->getMessage() . ' Falling back to default (none installed).',
            );
        }
    }

    /**
     * @param list<mixed> $list
     */
    private function fromList(array $list): PluginConfig
    {
        if ($list === []) {
            return PluginConfig::none();
        }

        return PluginConfig::filtered($this->normalizeGlobs($list, 'bundled-skills'), []);
    }

    /**
     * @param array<string, mixed> $map
     */
    private function fromMap(array $map): PluginConfig
    {
        $include = $this->readListKey($map, 'include');
        $exclude = $this->readListKey($map, 'exclude');

        // Explicit empty include = user asked for "none".
        if ($include === []) {
            return PluginConfig::none();
        }

        return PluginConfig::filtered($include ?? [], $exclude ?? []);
    }

    /**
     * @param array<string, mixed> $map
     * @return list<string>|null null when the key is absent
     */
    private function readListKey(array $map, string $key): ?array
    {
        if (!array_key_exists($key, $map)) {
            return null;
        }

        $value = $map[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidPluginConfigException(sprintf(
                'bundled-skills.%s must be a list of glob strings.',
                $key,
            ));
        }

        return $this->normalizeGlobs($value, 'bundled-skills.' . $key);
    }

    /**
     * @param list<mixed> $items
     * @return list<string>
     */
    private function normalizeGlobs(array $items, string $pathLabel): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            if (!is_string($item)) {
                throw new InvalidPluginConfigException(sprintf(
                    '%s[%d] must be a string glob, got %s.',
                    $pathLabel,
                    $i,
                    get_debug_type($item),
                ));
            }
            $out[] = $item;
        }

        return $out;
    }
}
