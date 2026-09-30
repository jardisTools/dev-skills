<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Discovery;

use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\ProcessDocsMode;
use JardisTools\DevSkills\Exception\InvalidPluginConfigException;

final class ReadPluginConfig
{
    private const ROOT_KEY = 'jardis/dev-skills';
    private const BUNDLED_KEY = 'bundled-skills';
    private const PROCESS_DOCS_KEY = 'process-docs';

    /**
     * @param array<string, mixed> $extra the value returned by
     *                                    $composer->getPackage()->getExtra()
     */
    public function __invoke(array $extra): PluginConfig
    {
        $root = $extra[self::ROOT_KEY] ?? null;
        if (!is_array($root)) {
            return PluginConfig::all();
        }

        [$mode, $modeWarning] = $this->readProcessDocs($root);

        return $this->readBundledSkills($root)->withProcessDocs($mode, $modeWarning);
    }

    /**
     * `process-docs` is independent of `bundled-skills`. An absent key means `committed`; any
     * other value than the two modes is reported and treated as `local`, so nothing can land
     * in a customer repository by accident.
     *
     * @param array<array-key, mixed> $root
     * @return array{ProcessDocsMode, ?string}
     */
    private function readProcessDocs(array $root): array
    {
        if (!array_key_exists(self::PROCESS_DOCS_KEY, $root)) {
            return [ProcessDocsMode::Committed, null];
        }

        $raw = $root[self::PROCESS_DOCS_KEY];
        $mode = is_string($raw) ? ProcessDocsMode::tryFrom($raw) : null;
        if ($mode !== null) {
            return [$mode, null];
        }

        return [ProcessDocsMode::Local, sprintf(
            'process-docs must be "committed" or "local"; got %s. Treated as process-docs=local.',
            is_string($raw) ? '"' . $raw . '"' : get_debug_type($raw),
        )];
    }

    /**
     * @param array<array-key, mixed> $root
     */
    private function readBundledSkills(array $root): PluginConfig
    {
        if (!array_key_exists(self::BUNDLED_KEY, $root)) {
            return PluginConfig::all();
        }

        $raw = $root[self::BUNDLED_KEY];

        if ($raw === true) {
            return PluginConfig::all();
        }
        if ($raw === false) {
            return $this->mandatoryOnly();
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
            return PluginConfig::invalid($e->getMessage());
        }
    }

    private function mandatoryOnly(): PluginConfig
    {
        return PluginConfig::onlyMandatory('bundled-skills=false: ' . PluginConfig::MANDATORY_NOTICE);
    }

    /**
     * @param list<mixed> $list
     */
    private function fromList(array $list): PluginConfig
    {
        if ($list === []) {
            return $this->mandatoryOnly();
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

        // Explicit empty include = user asked for "none" (same as an empty list).
        if ($include === []) {
            return $this->mandatoryOnly();
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
