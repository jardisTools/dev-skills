<?php

declare(strict_types=1);

namespace JardisTools\DevSkills\Handler\Shell;

use Closure;
use JardisTools\DevSkills\Data\ReviewerSource;

/**
 * The reviewer shell for Codex: a TOML file with the required fields `name`, `description` and
 * `developer_instructions` (the pointer to the source). No model field is ever written.
 */
final class RenderCodexShell
{
    /**
     * @param Closure(string): string $encodeBasicString
     * @param Closure(string): string $encodeMultiline
     * @param Closure(ReviewerSource): string $buildBody
     */
    public function __construct(
        private readonly Closure $encodeBasicString,
        private readonly Closure $encodeMultiline,
        private readonly Closure $buildBody,
    ) {
    }

    public function __invoke(ReviewerSource $source): string
    {
        return 'name = ' . ($this->encodeBasicString)($source->name) . "\n"
            . 'description = ' . ($this->encodeBasicString)($source->description) . "\n"
            . 'developer_instructions = ' . ($this->encodeMultiline)(($this->buildBody)($source)) . "\n";
    }
}
