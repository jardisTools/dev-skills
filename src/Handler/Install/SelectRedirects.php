<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\Manifest;
use JardisTools\DevSkills\Data\PluginConfig;
use JardisTools\DevSkills\Data\RenamedSkills;
use JardisTools\DevSkills\Data\SkillDescriptor;
use JardisTools\DevSkills\Handler\Discovery\ScanPluginSkills;

/**
 * Decides which old bundle names get a redirect skill in this run: those whose
 * folder the plugin manages in `.claude/skills` (an entry of the previous
 * manifest, real or legacy) and still exists, whose new name is part of the
 * selection, and which the config still asks for under the old name (the
 * config filter is applied to the old names as if they were bundle skills:
 * everything for `true`/absent, nothing for `false`, the matching globs
 * otherwise). A fresh project has no such entry and so gets none; an old
 * folder that gets no redirect is removed as stale.
 */
final class SelectRedirects
{
    /**
     * @param Closure(list<SkillDescriptor>, PluginConfig): list<SkillDescriptor> $filterBundledSkills
     */
    public function __construct(
        private readonly Closure $filterBundledSkills,
    ) {
    }

    /**
     * @param list<SkillDescriptor> $selected final selection of this run
     * @return list<SkillDescriptor> one descriptor per redirect, named like the old skill, without source folder
     */
    public function __invoke(?Manifest $previous, array $selected, PluginConfig $config, string $projectRoot): array
    {
        if ($previous === null) {
            return [];
        }

        $selectedNames = array_map(static fn (SkillDescriptor $s): string => $s->name, $selected);
        $oldNames = array_map(
            static fn (string $old): SkillDescriptor => new SkillDescriptor($old, '', ScanPluginSkills::SOURCE_PACKAGE),
            array_keys(RenamedSkills::MAPPING),
        );
        $requested = array_map(
            static fn (SkillDescriptor $s): string => $s->name,
            ($this->filterBundledSkills)($oldNames, $config),
        );

        $redirects = [];
        foreach (RenamedSkills::MAPPING as $oldName => $newName) {
            $key = ResolveTargets::CLAUDE_SKILLS_DIR . '/' . $oldName;
            $entry = $previous->entries[$key] ?? null;

            if (
                $entry !== null
                && in_array($oldName, $requested, true)
                && $entry['source'] === ScanPluginSkills::SOURCE_PACKAGE
                && in_array($newName, $selectedNames, true)
                && is_dir($projectRoot . '/' . $key)
            ) {
                $redirects[] = new SkillDescriptor($oldName, '', ScanPluginSkills::SOURCE_PACKAGE);
            }
        }

        return $redirects;
    }
}
