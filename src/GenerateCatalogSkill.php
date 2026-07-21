<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use JardisTools\DevSkills\Data\CatalogEntry;
use JardisTools\DevSkills\Handler\Build\ParseManifest;
use JardisTools\DevSkills\Handler\Build\RenderCatalogTable;
use JardisTools\DevSkills\Handler\Build\RenderFrontmatter;
use JardisTools\DevSkills\Handler\Build\WriteSkill;

/**
 * Orchestrator: reads catalog/manifest.json and writes the rendered
 * skills/jardis-catalog/SKILL.md. Composed of atomic Closures; contains
 * no own logic — only pipeline wiring (Closure-Orchestrator pattern).
 */
final class GenerateCatalogSkill
{
    /** @var Closure(string): list<CatalogEntry> */
    private readonly Closure $parseManifest;

    /** @var Closure(): string */
    private readonly Closure $renderFrontmatter;

    /** @var Closure(list<CatalogEntry>): string */
    private readonly Closure $renderCatalogTable;

    /** @var Closure(string, string): void */
    private readonly Closure $writeSkill;

    public function __construct()
    {
        $this->parseManifest      = (new ParseManifest())->__invoke(...);
        $this->renderFrontmatter  = (new RenderFrontmatter())->__invoke(...);
        $this->renderCatalogTable = (new RenderCatalogTable())->__invoke(...);
        $this->writeSkill         = (new WriteSkill())->__invoke(...);
    }

    public function __invoke(string $manifestPath, string $targetPath): void
    {
        $entries = ($this->parseManifest)($manifestPath);
        $content = ($this->renderFrontmatter)()
            . "\n"
            . ($this->renderCatalogTable)($entries);

        ($this->writeSkill)($targetPath, $content);
    }
}
