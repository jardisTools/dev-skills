<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\CatalogEntry;
use JardisTools\DevSkills\Data\SkillDescriptor;

/**
 * Replaces the full AGENTS.md content of each vendor package by its short block, so the aggregated
 * AGENTS.md stays small; the details live in the skills and the docs.
 */
final class DigestAgentsDescriptors
{
    /** @var Closure(AgentsDescriptor, ?CatalogEntry, list<string>): AgentsDescriptor */
    private readonly Closure $buildDigest;

    public function __construct(?Closure $buildDigest = null)
    {
        $this->buildDigest = $buildDigest ?? (new BuildPackageDigest())->__invoke(...);
    }

    /**
     * @param list<AgentsDescriptor> $descriptors
     * @param list<CatalogEntry> $catalog
     * @param list<SkillDescriptor> $vendorSkills the skills found in the vendor packages
     * @return list<AgentsDescriptor>
     */
    public function __invoke(array $descriptors, array $catalog, array $vendorSkills): array
    {
        $entries = [];
        foreach ($catalog as $entry) {
            $entries[$entry->package] = $entry;
        }

        $skillsByPackage = [];
        foreach ($vendorSkills as $skill) {
            $skillsByPackage[$skill->sourcePackage][] = $skill->name;
        }

        return array_map(
            fn (AgentsDescriptor $descriptor): AgentsDescriptor => ($this->buildDigest)(
                $descriptor,
                $entries[$descriptor->sourcePackage] ?? null,
                $skillsByPackage[$descriptor->sourcePackage] ?? [],
            ),
            $descriptors,
        );
    }
}
