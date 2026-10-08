<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Install;

use Closure;
use JardisTools\DevSkills\Data\AgentsDescriptor;
use JardisTools\DevSkills\Data\CatalogEntry;

/**
 * Condenses the AGENTS.md of one vendor package into the short block: what it is (catalog capability, else the
 * intro of the package), when to use it (catalog `use_when`) and how (the skills to load, the docs URL).
 */
final class BuildPackageDigest
{
    /** @var Closure(string): string */
    private readonly Closure $extractIntro;

    /** @var Closure(string): ?string */
    private readonly Closure $extractDocsUrl;

    public function __construct(?Closure $extractIntro = null, ?Closure $extractDocsUrl = null)
    {
        $this->extractIntro = $extractIntro ?? (new ExtractPackageIntro())->__invoke(...);
        $this->extractDocsUrl = $extractDocsUrl ?? (new ExtractDocsUrl())->__invoke(...);
    }

    /**
     * @param list<string> $skillNames skills the package ships, in the order to load them
     */
    public function __invoke(AgentsDescriptor $descriptor, ?CatalogEntry $entry, array $skillNames): AgentsDescriptor
    {
        $lines = ['# ' . $descriptor->sourcePackage, ''];

        $summary = $entry !== null
            ? $this->sentence($entry->capability)
            : ($this->extractIntro)($descriptor->content);
        if ($summary !== '') {
            $lines[] = $summary;
            $lines[] = '';
        }

        if ($entry !== null) {
            $lines[] = '- **Use when:** ' . $entry->useWhen;
        }

        $how = $this->howLine($skillNames, ($this->extractDocsUrl)($descriptor->content));
        if ($how !== null) {
            $lines[] = $how;
        }

        return new AgentsDescriptor($descriptor->sourcePackage, rtrim(implode("\n", $lines)));
    }

    private function sentence(string $capability): string
    {
        $text = ucfirst(trim($capability));

        return str_ends_with($text, '.') ? $text : $text . '.';
    }

    /**
     * @param list<string> $skillNames
     */
    private function howLine(array $skillNames, ?string $docsUrl): ?string
    {
        if ($skillNames === []) {
            return $docsUrl !== null ? '- **How:** Full reference: ' . $docsUrl : null;
        }

        $quoted = implode(', ', array_map(static fn (string $name): string => '`' . $name . '`', $skillNames));
        $line = sprintf(
            '- **How:** load %s %s before touching the API — %s the usage essentials and pitfalls.',
            count($skillNames) === 1 ? 'skill' : 'skills',
            $quoted,
            count($skillNames) === 1 ? 'it carries' : 'they carry',
        );

        return $docsUrl !== null ? $line . "\n  Full reference: " . $docsUrl : $line;
    }
}
