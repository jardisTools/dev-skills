<?php

declare(strict_types=1);

namespace JardisTools\DevSkills;

use Closure;
use JardisTools\DevSkills\Data\CatalogEntry;
use JardisTools\DevSkills\Handler\Build\ParseManifest;
use JardisTools\DevSkills\Handler\Build\RenderCatalogTable;
use JardisTools\DevSkills\Handler\Build\RenderFrontmatter;

/**
 * Orchestrator: checks that the checked-in skills/jardis-catalog/SKILL.md
 * is byte-identical to what would be generated from catalog/manifest.json.
 *
 * Throws RuntimeException when drift is detected. Returns void on success.
 * Git-independent: can be used as a PHPUnit assertion or a bin --check guard.
 *
 * Composed of the same rendering Closures as GenerateCatalogSkill; contains
 * no own logic — only pipeline wiring (Closure-Orchestrator pattern).
 */
final class CheckCatalogDrift
{
    /** @var Closure(string): list<CatalogEntry> */
    private readonly Closure $parseManifest;

    /** @var Closure(): string */
    private readonly Closure $renderFrontmatter;

    /** @var Closure(list<CatalogEntry>): string */
    private readonly Closure $renderCatalogTable;

    public function __construct()
    {
        $this->parseManifest      = (new ParseManifest())->__invoke(...);
        $this->renderFrontmatter  = (new RenderFrontmatter())->__invoke(...);
        $this->renderCatalogTable = (new RenderCatalogTable())->__invoke(...);
    }

    /**
     * @throws \RuntimeException when the checked-in SKILL.md differs from
     *                           the content the generator would produce, or
     *                           when the target file cannot be read.
     */
    public function __invoke(string $manifestPath, string $targetPath): void
    {
        $entries   = ($this->parseManifest)($manifestPath);
        $generated = ($this->renderFrontmatter)()
            . "\n"
            . ($this->renderCatalogTable)($entries);

        if (!is_file($targetPath)) {
            throw new \RuntimeException(
                sprintf('Target SKILL.md not found: %s', $targetPath),
            );
        }

        $existing = file_get_contents($targetPath);

        if ($existing === false) {
            throw new \RuntimeException(
                sprintf('Cannot read target SKILL.md: %s', $targetPath),
            );
        }

        if ($generated !== $existing) {
            throw new \RuntimeException(
                sprintf(
                    "Drift detected: the checked-in %s differs from the content the generator would produce.\n"
                    . "Run `php bin/generate-catalog.php` (or `make generate-catalog`) to update.",
                    $targetPath,
                ),
            );
        }
    }
}
